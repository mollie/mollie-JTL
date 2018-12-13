<?php
/**
 * Created by PhpStorm.
 * User: spookee
 * Date: 10.12.18
 * Time: 14:03
 */

require_once __DIR__ . '/../class/Helper.php';

use Mollie\Api\Types\PaymentStatus as MolliePaymentStatus;

class JTLMollie extends PaymentMethod
{

    /**
     * PaymentMethod identifier
     */
    const MOLLIE_METHOD = "";

    /**
     * Use OrderAPI for this PaymentMethod
     */
    const ORDER_API = true;

    /**
     * @var \ws_mollie\Helper
     */
    protected static $_helper;

    /**
     * @var \Mollie\Api\MollieApiClient
     */
    protected static $_mollie;


    public function __construct($moduleID, $nAgainCheckout = 0)
    {
        parent::__construct($moduleID, $nAgainCheckout);
        \ws_mollie\Helper::init();
    }

    /**
     * @return \Mollie\Api\MollieApiClient
     * @throws \Mollie\Api\Exceptions\ApiException
     * @throws \Mollie\Api\Exceptions\IncompatiblePlatform
     */
    public static function API()
    {
        if (self::$_mollie === null) {
            self::$_mollie = new \Mollie\Api\MollieApiClient();
            self::$_mollie->setApiKey(\ws_mollie\Helper::getSetting('api_key'));
        }
        return self::$_mollie;
    }

    /**
     * @return \ws_mollie\Helper
     */
    public static function Helper()
    {
        if (self::$_helper === null) {
            self::$_helper = new ws_mollie\Helper();
        }
        return self::$_helper;
    }

    /**
     * @param Bestellung $order
     * @return array
     */
    protected function getOrderData(Bestellung $order, $hash)
    {
        $data = [
            'locale' => 'de_DE', // TODO: mapping with language?
            'amount' => [
                'currency' => $order->Waehrung->cISO,
                'value' => number_format($order->fGesamtsummeKundenwaehrung, 2, '.', ''),
            ],
            'orderNumber' => $order->cBestellNr,
            'lines' => [],
            'billingAddress' => new stdClass(),
            'redirectUrl' => (int)$this->duringCheckout ? Shop::getURL() . '/bestellabschluss.php?mollie=' . md5($hash) : $this->getReturnURL($order),
            'webhookUrl' => $this->getNotificationURL($hash)
        ];
        if (static::MOLLIE_METHOD !== '') {
            $data['method'] = static::MOLLIE_METHOD;
        }
        $data['billingAddress']->organizationName = utf8_encode($order->oRechnungsadresse->cFirma);
        $data['billingAddress']->title = $order->oRechnungsadresse->cAnrede === 'm' ? 'Herr' : 'Frau'; // TODO: Sprachvariable
        $data['billingAddress']->givenName = utf8_encode($order->oRechnungsadresse->cVorname);
        $data['billingAddress']->familyName = utf8_encode($order->oRechnungsadresse->cNachname);
        $data['billingAddress']->email = $order->oRechnungsadresse->cMail;
        $data['billingAddress']->streetAndNumber = utf8_encode($order->oRechnungsadresse->cStrasse . ' ' . $order->oRechnungsadresse->cHausnummer);
        $data['billingAddress']->postalCode = $order->oRechnungsadresse->cPLZ;
        $data['billingAddress']->city = utf8_encode($order->oRechnungsadresse->cOrt);
        $data['billingAddress']->country = $order->oRechnungsadresse->cLand;

        /** @var WarenkorbPos $oPosition */
        foreach ($order->Positionen as $oPosition) {
            $line = new stdClass();
            switch ((int)$oPosition->nPosTyp) {
                case (int)C_WARENKORBPOS_TYP_GRATISGESCHENK:
                case (int)C_WARENKORBPOS_TYP_ARTIKEL:
                    $line->type = \Mollie\Api\Types\OrderLineType::TYPE_PHYSICAL;
                    $line->name = utf8_encode($oPosition->cName);
                    $line->quantity = $oPosition->nAnzahl;
                    $line->unitPrice = (object)[
                        'value' => number_format($oPosition->fPreis * ((float)$oPosition->fMwSt / 100 + 1), 2, '.', ''),
                        'currency' => $order->Waehrung->cISO,
                    ];
                    $line->totalAmount = (object)[
                        'value' => number_format($oPosition->fPreis * $oPosition->nAnzahl * ((float)$oPosition->fMwSt / 100 + 1), 2, '.', ''),
                        'currency' => $order->Waehrung->cISO,
                    ];
                    $line->vatRate = $oPosition->fMwSt;
                    $line->vatAmount = (object)[
                        'value' => number_format($line->totalAmount->value - ($oPosition->fPreis * $oPosition->nAnzahl), 2, '.', ''),
                        'currency' => $order->Waehrung->cISO,
                    ];
                    $line->sku = $oPosition->cArtNr;
                    break;
                case (int)C_WARENKORBPOS_TYP_VERSANDPOS:
                    $line->type = \Mollie\Api\Types\OrderLineType::TYPE_SHIPPING_FEE;
                    $line->name = utf8_encode($oPosition->cName);
                    $line->quantity = $oPosition->nAnzahl;
                    $line->unitPrice = (object)[
                        'value' => number_format($oPosition->fPreis * ((float)$oPosition->fMwSt / 100 + 1), 2, '.', ''),
                        'currency' => $order->Waehrung->cISO,
                    ];
                    $line->totalAmount = (object)[
                        'value' => number_format($oPosition->fPreis * $oPosition->nAnzahl * ((float)$oPosition->fMwSt / 100 + 1), 2, '.', ''),
                        'currency' => $order->Waehrung->cISO,
                    ];
                    $line->vatRate = $oPosition->fMwSt;
                    $line->vatAmount = (object)[
                        'value' => number_format($line->totalAmount->value - ($oPosition->fPreis * $oPosition->nAnzahl), 2, '.', ''),
                        'currency' => $order->Waehrung->cISO,
                    ];
                    break;
                case (int)C_WARENKORBPOS_TYP_VERPACKUNG:
                case (int)C_WARENKORBPOS_TYP_VERSANDZUSCHLAG:
                case (int)C_WARENKORBPOS_TYP_ZAHLUNGSART:
                case (int)C_WARENKORBPOS_TYP_VERSAND_ARTIKELABHAENGIG:
                case (int)C_WARENKORBPOS_TYP_TRUSTEDSHOPS:
                    $line->type = \Mollie\Api\Types\OrderLineType::TYPE_SURCHARGE;
                    $line->name = utf8_encode($oPosition->cName);
                    $line->quantity = $oPosition->nAnzahl;
                    $line->unitPrice = (object)[
                        'value' => number_format($oPosition->fPreis * ((float)$oPosition->fMwSt / 100 + 1), 2, '.', ''),
                        'currency' => $order->Waehrung->cISO,
                    ];
                    $line->totalAmount = (object)[
                        'value' => number_format($oPosition->fPreis * $oPosition->nAnzahl * ((float)$oPosition->fMwSt / 100 + 1), 2, '.', ''),
                        'currency' => $order->Waehrung->cISO,
                    ];
                    $line->vatRate = $oPosition->fMwSt;
                    $line->vatAmount = (object)[
                        'value' => number_format($line->totalAmount->value - ($oPosition->fPreis * $oPosition->nAnzahl), 2, '.', ''),
                        'currency' => $order->Waehrung->cISO,
                    ];
                    break;
                case (int)C_WARENKORBPOS_TYP_GUTSCHEIN:
                case (int)C_WARENKORBPOS_TYP_KUPON:
                case (int)C_WARENKORBPOS_TYP_NEUKUNDENKUPON:
                    $line->type = \Mollie\Api\Types\OrderLineType::TYPE_DISCOUNT;
                    $line->name = $oPosition->cName;
                    $line->quantity = $oPosition->nAnzahl;
                    $line->unitPrice = (object)[
                        'value' => number_format($oPosition->fPreis * ((float)$oPosition->fMwSt / 100 + 1), 2, '.', ''),
                        'currency' => $order->Waehrung->cISO,
                    ];
                    $line->totalAmount = (object)[
                        'value' => number_format($oPosition->fPreis * $oPosition->nAnzahl * ((float)$oPosition->fMwSt / 100 + 1), 2, '.', ''),
                        'currency' => $order->Waehrung->cISO,
                    ];
                    $line->vatRate = $oPosition->fMwSt;
                    $line->vatAmount = (object)[
                        'value' => number_format($line->totalAmount->value - ($oPosition->fPreis * $oPosition->nAnzahl), 2, '.', ''),
                        'currency' => $order->Waehrung->cISO,
                    ];
                    break;
            }
            if (isset($line->type)) {
                $data['lines'][] = $line;
            }
        }

        if ((int)$order->GuthabenNutzen === 1 && $order->fGuthaben < 0) {
            $line = new stdClass();
            $line->type = \Mollie\Api\Types\OrderLineType::TYPE_STORE_CREDIT;
            $line->name = 'Guthaben';
            $line->quantity = 1;
            $line->unitPrice = (object)[
                'value' => number_format($order->fGuthaben, 2, '.', ''),
                'currency' => $order->Waehrung->cISO,
            ];
            $line->unitPrice = (object)[
                'value' => number_format($order->fGuthaben, 2, '.', ''),
                'currency' => $order->Waehrung->cISO,
            ];
            $line->totalAmount = (object)[
                'value' => number_format($order->fGuthaben, 2, '.', ''),
                'currency' => $order->Waehrung->cISO,
            ];
            $line->vatRate = "0.00";
            $line->vatAmount = (object)[
                'value' => number_format(0, 2, '.', ''),
                'currency' => $order->Waehrung->cISO,
            ];
            $data['lines'][] = $line;
        }
        return $data;
    }

    /**
     * Prepares everything so that the Customer can start the Payment Process.
     * Tells Template Engine.
     *
     * @param Bestellung $order
     */
    public function preparePaymentProcess($order)
    {
        try {
            $hash = $this->generateHash($order);
            $oMolliePayment = self::API()->orders->create($this->getOrderData($order, $hash));
            $_SESSION['oMolliePayment'] = $oMolliePayment;
            $this->doLog('Mollie Create Payment Redirect: ' . $oMolliePayment->getCheckoutUrl() . "<br/><pre>" . print_r($oMolliePayment, 1) . "</pre>", LOGLEVEL_DEBUG);
            \ws_mollie\Model\Payment::updateFromPayment($oMolliePayment, $order->kBestellung, md5($hash));
            Shop::Smarty()->assign('oMolliePayment', $oMolliePayment);
            header('Location: ' . $oMolliePayment->getCheckoutUrl());
            exit();
        } catch (\Mollie\Api\Exceptions\ApiException $e) {
            Shop::Smarty()->assign('oMollieException', $e);
            $this->doLog("Create Payment Error: " . $e->getMessage() . '<br/>><pre>' . print_r($data, 1) . '</pre>');
        }
    }

    /**
     * @param Bestellung $order
     * @param string $hash
     * @param array $args
     */
    public function handleNotification($order, $hash, $args)
    {
        \ws_mollie\Helper::autoload();
        try {
            $oMolliePayment = self::API()->orders->get($args['id']);
            $this->doLog('Received Notification<br/><pre>' . print_r([$hash, $args, $oMolliePayment], 1) . '</pre>', LOGLEVEL_DEBUG);

            \ws_mollie\Model\Payment::updateFromPayment($oMolliePayment, $order->kBestellung);

            if ($oMolliePayment->status === MolliePaymentStatus::STATUS_PAID) {
                $oIncomingPayment = new stdClass();
                $oIncomingPayment->fBetrag = $order->fGesamtsummeKundenwaehrung;
                $oIncomingPayment->cISO = $order->Waehrung->cISO;
                $oIncomingPayment->chinweis = $oMolliePayment->id;
                $this->addIncomingPayment($order, $oIncomingPayment);
                $this->setOrderStatusToPaid($order);
            }

        } catch (\Exception $e) {
            $this->doLog($e->getMessage());
        }
    }

    /**
     * @param Bestellung $order
     * @param string $hash
     * @param array $args
     *
     * @return true, if $order should be finalized
     */
    public function finalizeOrder($order, $hash, $args)
    {
        try {
            \ws_mollie\Helper::autoload();
            $oMolliePayment = self::API()->orders->get($args['id'], ['embed' => 'payments']);
            $this->doLog('Received Notification Finalize Order<br/><pre>' . print_r([$hash, $args, $oMolliePayment], 1) . '</pre>', LOGLEVEL_DEBUG);
            \ws_mollie\Model\Payment::updateFromPayment($oMolliePayment, $order->kBestellung);
            return !in_array([MolliePaymentStatus::STATUS_FAILED, MolliePaymentStatus::STATUS_CANCELED, MolliePaymentStatus::STATUS_EXPIRED, MolliePaymentStatus::STATUS_OPEN], $oMolliePayment->status);
        } catch (\Exception $e) {
            $this->doLog($e->getMessage());
        }
        return false;
    }

    /**
     * @return bool
     */
    public function canPayAgain()
    {
        return true;
    }

    /**
     * determines, if the payment method can be selected in the checkout process
     *
     * @return bool
     */
    public function isSelectable()
    {

        if (static::MOLLIE_METHOD !== '') {
            try {
                $method = self::API()->methods->get(static::MOLLIE_METHOD, ['locale' => 'de_DE', 'include' => 'pricing,issuers']);
                \Shop::DB()->executeQueryPrepared("UPDATE tzahlungsart SET cBild = :cBild WHERE cModulId = :cModulId", [':cBild' => $method->image->size2x, ':cModulId' => $this->cModulId], 3);
                $this->cBild = $method->image->size2x;
                return true;
            } catch (Exception $e) {
                $this->doLog('Method ' . static::MOLLIE_METHOD . ' not selectable:' . $e->getMessage());
                return false;
            }
        }
        return true;
    }

    /**
     *
     * @param object $customer
     * @param Warenkorb $cart
     * @return bool - true, if $customer with $cart may use Payment Method
     */
    public function isValid($customer, $cart)
    {
        if (\ws_mollie\Helper::init() && \ws_mollie\Helper::getSetting("api_key")) {
            return true;
        }
        $this->doLog("isValdid failed: init failed or no API Key given. Try clear the Cache.");
        return false;
    }

    /**
     * @param array $args_arr
     * @return bool
     */
    public function isValidIntern($args_arr = [])
    {
        if (\ws_mollie\Helper::init() && \ws_mollie\Helper::getSetting("api_key")) {
            return true;
        }
        $this->doLog("isValdid failed: init failed or no API Key given. Try clear the Cache.");
        return false;
    }

    /**
     * @return bool
     */
    public function redirectOnCancel()
    {
        return parent::redirectOnCancel(); // TODO: Change the autogenerated stub
    }
}