<?php

/**
 * @copyright 2022 WebStollen GmbH
 * @link https://www.webstollen.de
 */

use Mollie\Api\Exceptions\ApiException;
use Mollie\Api\Exceptions\IncompatiblePlatform;
use Mollie\Api\MollieApiClient;
use Mollie\Api\Types\OrderLineType;
use Mollie\Api\Types\OrderStatus;
use ws_mollie\Helper;
use ws_mollie\Model\Payment;
use ws_mollie\Mollie;

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../class/Helper.php';
require_once __DIR__ . '/../../../../../modules/PaymentMethod.class.php';

class JTLMollie extends PaymentMethod
{
    /**
     * PaymentMethod identifier
     */
    const MOLLIE_METHOD = '';

    /**
     * Use OrderAPI for this PaymentMethod
     */
    const ORDER_API = true;

    /**
     * @var Helper
     */
    protected static $_helper;

    /**
     * @var MollieApiClient
     */
    protected static $_mollie;
    /**
     * @var array
     */
    protected static $_possiblePaymentMethods = [];
    /**
     * @var string
     */
    public $cBild;

    public function __construct($moduleID, $nAgainCheckout = 0)
    {
        parent::__construct($moduleID, $nAgainCheckout);
        Helper::init();
    }

    /**
     * @return Helper
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
     * @param Object     $payment (Key, Zahlungsanbieter, Abgeholt, Zeit is set here)
     * @return $this
     */
    public function addIncomingPayment($order, $payment)
    {
        $model = (object)array_merge([
            'kBestellung'       => (int)$order->kBestellung,
            'cZahlungsanbieter' => empty($order->cZahlungsartName) ? $this->name : $order->cZahlungsartName,
            'fBetrag'           => 0,
            'fZahlungsgebuehr'  => 0,
            'cISO'              => $_SESSION['Waehrung']->cISO,
            'cEmpfaenger'       => '',
            'cZahler'           => '',
            'dZeit'             => 'now()',
            'cHinweis'          => '',
            'cAbgeholt'         => 'N'
        ], (array)$payment);
        if (isset($model->kZahlungseingang) && $model->kZahlungseingang > 0) {
            Shop::DB()->update('tzahlungseingang', 'kZahlungseingang', $model->kZahlungseingang, $model);
        } else {
            Shop::DB()->insert('tzahlungseingang', $model);
        }

        return $this;
    }

    /**
     * @param Bestellung $order
     * @return PaymentMethod|void
     */
    public function setOrderStatusToPaid($order)
    {
        // If paid already, do nothing
        if ((int)$order->cStatus >= BESTELLUNG_STATUS_BEZAHLT) {
            return;
        }
        parent::setOrderStatusToPaid($order);
    }

    /**
     * Prepares everything so that the Customer can start the Payment Process.
     * Tells Template Engine.
     *
     * @param Bestellung $order
     */
    public function preparePaymentProcess($order)
    {
        $logData = '#' . $order->kBestellung . '' . $order->cBestellNr;

        try {
            $payment = Payment::getPayment($order->kBestellung);
            if ($payment && in_array($payment->cStatus, [OrderStatus::STATUS_CREATED]) && $payment->cCheckoutURL) {
                $logData .= '$' . $payment->kID;
                if (!$this->duringCheckout) {
                    Session::getInstance()->cleanUp();
                }
                header('Location: ' . $payment->cCheckoutURL);
                exit();
            }
        } catch (Exception $e) {
            $this->doLog('Get Payment Error: ' . $e->getMessage() . '. Create new ORDER...', $logData);
        }

        try {
            $hash                       = $this->generateHash($order);
            $oMolliePayment             = self::API()->orders->create($this->getOrderData($order, $hash));
            $_SESSION['oMolliePayment'] = $oMolliePayment;
            $logData .= '$' . $oMolliePayment->id;
            $this->doLog('Mollie Create Payment Redirect: ' . $oMolliePayment->getCheckoutUrl() . '<br/><pre>' . print_r($oMolliePayment, 1) . '</pre>', $logData, LOGLEVEL_DEBUG);
            Payment::updateFromPayment($oMolliePayment, $order->kBestellung, md5($hash));
            Shop::Smarty()->assign('oMolliePayment', $oMolliePayment);
            if (!$this->duringCheckout) {
                Session::getInstance()->cleanUp();
            }
            header('Location: ' . $oMolliePayment->getCheckoutUrl());
            exit();
        } catch (ApiException $e) {
            Shop::Smarty()->assign('oMollieException', $e);
            $this->doLog('Create Payment Error: ' . $e->getMessage() . '<br/><pre>' . print_r($e->getTrace(), 1) . '</pre>', $logData);
        }
    }

    /**
     * @param string $msg
     * @param null   $data
     * @param int    $level
     * @return $this
     */
    public function doLog($msg, $data = null, $level = LOGLEVEL_NOTICE)
    {
        ZahlungsLog::add($this->moduleID, $msg, $data, $level);

        return $this;
    }

    /**
     * @throws ApiException
     * @throws IncompatiblePlatform
     * @return MollieApiClient
     */
    public static function API()
    {
        if (self::$_mollie === null) {
            self::$_mollie = new MollieApiClient();
            self::$_mollie->setApiKey(Helper::getSetting('api_key'));
        }

        return self::$_mollie;
    }

    /**
     * @param Bestellung $order
     * @param $hash
     * @return array
     */
    protected function getOrderData(Bestellung $order, $hash)
    {
        $locale = self::getLocale($_SESSION['cISOSprache'], $_SESSION['Kunde']->cLand);
        $data   = [
            'locale' => $locale ?: 'de_DE',
            'amount' => (object)[
                'currency' => $order->Waehrung->cISO,
                'value'    => number_format($order->fGesamtsummeKundenwaehrung, 2, '.', ''),
            ],
            'orderNumber'     => $order->cBestellNr,
            'lines'           => [],
            'billingAddress'  => new stdClass(),
            'shippingAddress' => new stdClass(),
            'redirectUrl'     => (int)$this->duringCheckout ? Shop::getURL() . '/bestellabschluss.php?mollie=' . md5($hash) : $this->getReturnURL($order),
            'webhookUrl'      => $this->getNotificationURL($hash) . '&hash=' . md5($hash),
        ];

        if (static::MOLLIE_METHOD !== '') {
            $data['method'] = static::MOLLIE_METHOD;
        }
        $data['billingAddress']->organizationName = utf8_encode($order->oRechnungsadresse->cFirma);
        $data['billingAddress']->title            = utf8_encode($order->oRechnungsadresse->cAnrede === 'm' ? Shop::Lang()->get('mr') : Shop::Lang()->get('mrs'));
        $data['billingAddress']->givenName        = utf8_encode($order->oRechnungsadresse->cVorname);
        $data['billingAddress']->familyName       = utf8_encode($order->oRechnungsadresse->cNachname);
        $data['billingAddress']->email            = $order->oRechnungsadresse->cMail;
        $data['billingAddress']->streetAndNumber  = utf8_encode($order->oRechnungsadresse->cStrasse . ' ' . $order->oRechnungsadresse->cHausnummer);
        $data['billingAddress']->postalCode       = $order->oRechnungsadresse->cPLZ;
        $data['billingAddress']->city             = utf8_encode($order->oRechnungsadresse->cOrt);
        $data['billingAddress']->country          = $order->oRechnungsadresse->cLand;

        //if ((int)$order->kLieferadresse) {
        $data['shippingAddress']->organizationName = utf8_encode($order->Lieferadresse->cFirma);
        $data['shippingAddress']->title            = utf8_encode($order->Lieferadresse->cAnrede === 'm' ? Shop::Lang()->get('mr') : Shop::Lang()->get('mrs'));
        $data['shippingAddress']->givenName        = utf8_encode($order->Lieferadresse->cVorname);
        $data['shippingAddress']->familyName       = utf8_encode($order->Lieferadresse->cNachname);
        $data['shippingAddress']->email            = $order->oRechnungsadresse->cMail;
        $data['shippingAddress']->streetAndNumber  = utf8_encode($order->Lieferadresse->cStrasse . ' ' . $order->Lieferadresse->cHausnummer);
        $data['shippingAddress']->postalCode       = $order->Lieferadresse->cPLZ;
        $data['shippingAddress']->city             = utf8_encode($order->Lieferadresse->cOrt);
        $data['shippingAddress']->country          = $order->Lieferadresse->cLand;
        //}

        /** @var WarenkorbPos $oPosition */
        foreach ($order->Positionen as $oPosition) {
            $line            = new stdClass();
            $line->name      = utf8_encode($oPosition->cName);
            $line->quantity  = $oPosition->nAnzahl;
            $line->unitPrice = (object)[
                'value'    => number_format($order->Waehrung->fFaktor * ($oPosition->fPreis * ((float)$oPosition->fMwSt / 100 + 1)), 2, '.', ''),
                'currency' => $order->Waehrung->cISO,
            ];
            $line->totalAmount = (object)[
                'value'    => number_format($oPosition->nAnzahl * (float)$line->unitPrice->value, 2, '.', ''),
                'currency' => $order->Waehrung->cISO,
            ];
            $line->vatRate   = $oPosition->fMwSt;
            $line->vatAmount = (object)[
                'value'    => number_format($line->totalAmount->value - ($line->totalAmount->value / (1 + (float)$oPosition->fMwSt / 100)), 2, '.', ''),
                'currency' => $order->Waehrung->cISO,
            ];

            switch ((int)$oPosition->nPosTyp) {
                case (int)C_WARENKORBPOS_TYP_GRATISGESCHENK:
                case (int)C_WARENKORBPOS_TYP_ARTIKEL:
                    $line->type = OrderLineType::TYPE_PHYSICAL;
                    $line->sku  = $oPosition->cArtNr;

                    break;
                case (int)C_WARENKORBPOS_TYP_VERSANDPOS:
                    $line->type = OrderLineType::TYPE_SHIPPING_FEE;

                    break;
                case (int)C_WARENKORBPOS_TYP_VERPACKUNG:
                case (int)C_WARENKORBPOS_TYP_VERSANDZUSCHLAG:
                case (int)C_WARENKORBPOS_TYP_ZAHLUNGSART:
                case (int)C_WARENKORBPOS_TYP_VERSAND_ARTIKELABHAENGIG:
                case (int)C_WARENKORBPOS_TYP_TRUSTEDSHOPS:
                    $line->type = OrderLineType::TYPE_SURCHARGE;

                    break;
                case (int)C_WARENKORBPOS_TYP_GUTSCHEIN:
                case (int)C_WARENKORBPOS_TYP_KUPON:
                case (int)C_WARENKORBPOS_TYP_NEUKUNDENKUPON:
                    $line->type = OrderLineType::TYPE_DISCOUNT;

                    break;
            }
            if (isset($line->type)) {
                $data['lines'][] = $line;
            }
        }

        if ((int)$order->GuthabenNutzen === 1 && $order->fGuthaben < 0) {
            $line            = new stdClass();
            $line->type      = OrderLineType::TYPE_STORE_CREDIT;
            $line->name      = 'Guthaben';
            $line->quantity  = 1;
            $line->unitPrice = (object)[
                'value'    => number_format($order->Waehrung->fFaktor * $order->fGuthaben, 2, '.', ''),
                'currency' => $order->Waehrung->cISO,
            ];
            $line->unitPrice = (object)[
                'value'    => number_format($order->Waehrung->fFaktor * $order->fGuthaben, 2, '.', ''),
                'currency' => $order->Waehrung->cISO,
            ];
            $line->totalAmount = (object)[
                'value'    => number_format($order->Waehrung->fFaktor * $order->fGuthaben, 2, '.', ''),
                'currency' => $order->Waehrung->cISO,
            ];
            $line->vatRate   = '0.00';
            $line->vatAmount = (object)[
                'value'    => number_format(0, 2, '.', ''),
                'currency' => $order->Waehrung->cISO,
            ];
            $data['lines'][] = $line;
        }

        // RUNDUNGSAUSGLEICH
        $sum = .0;
        foreach ($data['lines'] as $line) {
            $sum += (float)$line->totalAmount->value;
        }
        if (abs($sum - (float)$data['amount']->value) > 0) {
            $diff = (round((float)$data['amount']->value - $sum, 2));
            if ($diff != 0) {
                $line            = new stdClass();
                $line->type      = $diff > 0 ? OrderLineType::TYPE_SURCHARGE : OrderLineType::TYPE_DISCOUNT;
                $line->name      = 'Rundungsausgleich';
                $line->quantity  = 1;
                $line->unitPrice = (object)[
                    'value'    => number_format($diff, 2, '.', ''),
                    'currency' => $order->Waehrung->cISO,
                ];
                $line->unitPrice = (object)[
                    'value'    => number_format($diff, 2, '.', ''),
                    'currency' => $order->Waehrung->cISO,
                ];
                $line->totalAmount = (object)[
                    'value'    => number_format($diff, 2, '.', ''),
                    'currency' => $order->Waehrung->cISO,
                ];
                $line->vatRate   = '0.00';
                $line->vatAmount = (object)[
                    'value'    => number_format(0, 2, '.', ''),
                    'currency' => $order->Waehrung->cISO,
                ];
                $data['lines'][] = $line;
            }
        }

        return $data;
    }

    public static function getLocale($cISOSprache, $country = null)
    {
        switch ($cISOSprache) {
            case 'ger':
                if ($country === 'AT') {
                    return 'de_AT';
                }
                if ($country === 'CH') {
                    return 'de_CH';
                }

                return 'de_DE';
            case 'eng':
                return 'en_US';
            case 'fre':
                if ($country === 'BE') {
                    return 'fr_BE';
                }

                return 'fr_FR';
            case 'dut':
                if ($country === 'BE') {
                    return 'nl_BE';
                }

                return 'nl_NL';
            case 'spa':
                return 'es_ES';
            case 'ita':
                return 'it_IT';
            case 'pol':
                return 'pl_PL';
            case 'hun':
                return 'hu_HU';
            case 'por':
                return 'pt_PT';
            case 'nor':
                return 'nb_NO';
            case 'swe':
                return 'sv_SE';
            case 'fin':
                return 'fi_FI';
            case 'dan':
                return 'da_DK';
            case 'ice':
                return 'is_IS';
            default:
                return 'en_US';
        }
    }

    /**
     * @param Bestellung $order
     * @param string     $hash
     * @param array      $args
     */
    public function handleNotification($order, $hash, $args)
    {
        Helper::autoload();
        $logData = '#' . $order->kBestellung . '' . $order->cBestellNr;
        $this->doLog('Received Notification<br/><pre>' . print_r([$hash, $args], 1) . '</pre>', $logData, LOGLEVEL_NOTICE);

        try {
            $oMolliePayment = self::API()->orders->get($args['id']);
            Mollie::handleOrder($oMolliePayment, $order->kBestellung);
        } catch (Exception $e) {
            $this->doLog('handleNotification: ' . $e->getMessage(), $logData);
        }
    }

    /**
     * @param Bestellung $order
     * @param string     $hash
     * @param array      $args
     *
     * @return true, if $order should be finalized
     */
    public function finalizeOrder($order, $hash, $args)
    {
        $logData = '#' . $order->kBestellung . '' . $order->cBestellNr;

        try {
            Helper::autoload();
            $oMolliePayment = self::API()->orders->get($args['id'], ['embed' => 'payments']);
            $logData .= '$' . $oMolliePayment->id;
            $this->doLog('Received Notification Finalize Order<br/><pre>' . print_r([$hash, $args, $oMolliePayment], 1) . '</pre>', $logData, LOGLEVEL_DEBUG);
            Payment::updateFromPayment($oMolliePayment, $order->kBestellung);

            return in_array($oMolliePayment->status, [OrderStatus::STATUS_PAID, OrderStatus::STATUS_AUTHORIZED, OrderStatus::STATUS_PENDING, OrderStatus::STATUS_COMPLETED]);
        } catch (Exception $e) {
            $this->doLog($e->getMessage(), $logData);
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
        $locale = self::getLocale($_SESSION['cISOSprache'], $_SESSION['Kunde']->cLand);
        if (static::MOLLIE_METHOD !== '') {
            try {
                /** @var Warenkorb $wk */
                $wk     = $_SESSION['Warenkorb'];
                $method = self::PossiblePaymentMethods(static::MOLLIE_METHOD, $locale, $_SESSION['Kunde']->cLand, $_SESSION['Waehrung']->cISO, $wk->gibGesamtsummeWaren() * $_SESSION['Waehrung']->fFaktor);
                if ($method !== null) {
                    $this->updatePaymentMethod($_SESSION['cISOSprache'], $method);
                    $this->cBild = $method->image->size2x;

                    return true;
                }

                return false;
            } catch (Exception $e) {
                $this->doLog('Method ' . static::MOLLIE_METHOD . ' not selectable:' . $e->getMessage());

                return false;
            }
        }

        return true;
    }

    /**
     * @param $method
     * @param $locale
     * @param $billingCountry
     * @param $currency
     * @param $amount
     * @throws ApiException
     * @throws IncompatiblePlatform
     * @return null|mixed
     */
    protected static function PossiblePaymentMethods($method, $locale, $billingCountry, $currency, $amount)
    {
        $key = md5(serialize([$locale, $billingCountry, $amount, $currency]));
        if (!array_key_exists($key, self::$_possiblePaymentMethods)) {
            self::$_possiblePaymentMethods[$key] = self::API()->methods->allActive(['amount' => ['currency' => $currency, 'value' => number_format($amount, 2, '.', '')], 'billingCountry' => $_SESSION['Kunde']->cLand, 'locale' => $locale, 'include' => 'pricing,issuers', 'resource' => 'orders']);
        }
        if ($method !== null) {
            foreach (self::$_possiblePaymentMethods[$key] as $m) {
                if ($m->id === $method) {
                    return $m;
                }
            }

            return null;
        }

        return self::$_possiblePaymentMethods[$key];
    }

    /**
     * @param $cISOSprache
     * @param $method
     */
    protected function updatePaymentMethod($cISOSprache, $method)
    {
        if (ws_mollie\Helper::getSetting('paymentmethod_sync') === 'N') {
            return;
        }
        $size = ws_mollie\Helper::getSetting('paymentmethod_sync');
        if ((!isset($this->cBild) || $this->cBild === '') && isset($method->image->$size)) {
            Shop::DB()->executeQueryPrepared('UPDATE tzahlungsart SET cBild = :cBild WHERE cModulId = :cModulId', [':cBild' => $method->image->$size, ':cModulId' => $this->cModulId], 3);
        }
        if ($za = Shop::DB()->executeQueryPrepared('SELECT kZahlungsart FROM tzahlungsart WHERE cModulID = :cModulID', [':cModulID' => $this->moduleID], 1)) {
            Shop::DB()->executeQueryPrepared("INSERT INTO tzahlungsartsprache (kZahlungsart, cISOSprache, cName, cGebuehrname, cHinweisText) VALUES (:kZahlungsart, :cISOSprache, :cName, :cGebuehrname, :cHinweisText) ON DUPLICATE KEY UPDATE cName = IF(cName = '',:cName1,cName), cHinweisTextShop = IF(cHinweisTextShop = '' || cHinweisTextShop IS NULL,:cHinweisTextShop,cHinweisTextShop);", [
                ':kZahlungsart'     => (int)$za->kZahlungsart,
                ':cISOSprache'      => $cISOSprache,
                ':cName'            => utf8_decode($method->description),
                ':cGebuehrname'     => '',
                ':cHinweisText'     => '',
                ':cHinweisTextShop' => utf8_decode($method->description),
                'cName1'            => $method->description,
            ], 3);
        }
    }

    /**
     *
     * @param object    $customer
     * @param Warenkorb $cart
     * @return bool - true, if $customer with $cart may use Payment Method
     */
    public function isValid($customer, $cart)
    {
        if (Helper::init() && Helper::getSetting('api_key')) {
            return true;
        }
        $this->doLog('isValdid failed: init failed or no API Key given. Try clear the Cache.');

        return false;
    }

    /**
     * @param array $args_arr
     * @return bool
     */
    public function isValidIntern($args_arr = [])
    {
        if (Helper::init() && Helper::getSetting('api_key')) {
            return true;
        }
        $this->doLog('isValdid failed: init failed or no API Key given. Try clear the Cache.');

        return false;
    }
}
