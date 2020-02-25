<?php

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

    const ALLOW_PAYMENT_BEFORE_ORDER = true;

    /**
     * PaymentMethod identifier
     */
    const MOLLIE_METHOD = "";

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
     * @param Object $payment (Key, Zahlungsanbieter, Abgeholt, Zeit is set here)
     * @return $this
     * @throws Exception
     */
    public function addIncomingPayment($order, $payment)
    {
        $model = (object)array_merge([
            'kBestellung' => (int)$order->kBestellung,
            'cZahlungsanbieter' => empty($order->cZahlungsartName) ? $this->name : $order->cZahlungsartName,
            'fBetrag' => 0,
            'fZahlungsgebuehr' => 0,
            'cISO' => array_key_exists('Waehrung', $_SESSION) ? $_SESSION['Waehrung']->cISO : $payment->cISO,
            'cEmpfaenger' => '',
            'cZahler' => '',
            'dZeit' => 'now()',
            'cHinweis' => '',
            'cAbgeholt' => 'N'
        ], (array)$payment);

        $logData = '#' . $order->kBestellung;

        if (isset($model->kZahlungseingang) && $model->kZahlungseingang > 0) {
            Mollie::JTLMollie()->doLog('JTLMollie::addIncomingPayment (update)<br/><pre>' . print_r([$model, $payment], 1) . '</pre>', $logData);
            Shop::DB()->update('tzahlungseingang', 'kZahlungseingang', $model->kZahlungseingang, $model);
        } else {
            Mollie::JTLMollie()->doLog('JTLMollie::addIncomingPayment (create)<br/><pre>' . print_r([$model, $payment], 1) . '</pre>', $logData);
            Shop::DB()->insert('tzahlungseingang', $model);
        }

        return $this;
    }

    /**
     * @param string $msg
     * @param null $data
     * @param int $level
     * @return $this
     */
    public function doLog($msg, $data = null, $level = LOGLEVEL_NOTICE)
    {
        ZahlungsLog::add($this->moduleID, "[" . microtime(true) . " - " . $_SERVER['PHP_SELF'] . "] " . $msg, $data, $level);
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
            return $this;
        }
        parent::setOrderStatusToPaid($order);
    }

    /**
     * Prepares everything so that the Customer can start the Payment Process.
     * Tells Template Engine.
     *
     * @param Bestellung $order
     * @return bool|string
     */
    public function preparePaymentProcess($order)
    {
        $logData = '#' . $order->kBestellung . "§" . $order->cBestellNr;

        $payable = (float)$order->fGesamtsumme > 0;

        try {
            if ($order->kBestellung) {
                if ($payable) {
                    $payment = Payment::getPayment($order->kBestellung);
                    $oMolliePayment = self::API()->orders->get($payment->kID, ['embed' => 'payments']);
                    Mollie::handleOrder($oMolliePayment, $order->kBestellung);
                    if ($payment && in_array($payment->cStatus, [OrderStatus::STATUS_CREATED]) && $payment->cCheckoutURL) {
                        $logData .= '$' . $payment->kID;
                        if (!$this->duringCheckout) {
                            Session::getInstance()->cleanUp();
                        }
                        header('Location: ' . $payment->cCheckoutURL);
                        echo "<a href='{$oMolliePayment->getCheckoutUrl()}'>redirect to payment ...</a>";
                        exit();
                    }
                } else {
                    return Mollie::getOrderCompletedRedirect($order->kBestellung, true);
                }
            }
        } catch (Exception $e) {
            $this->doLog("Get Payment Error: " . $e->getMessage() . ". Create new ORDER...", $logData, LOGLEVEL_ERROR);
        }


        try {


            if (!$payable) {
                $bestellung = finalisiereBestellung();
                if ($bestellung && (int)$bestellung->kBestellung > 0) {
                    return Mollie::getOrderCompletedRedirect($bestellung->kBestellung, true);
                }
                header('Location: ' . Shop::getURL() . '/bestellvorgang.php?editZahlungsart=1&mollieStatus=failed');
                echo "<a href='" . Shop::getURL() . '/bestellvorgang.php?editZahlungsart=1&mollieStatus=failed' . "'>redirect...</a>";
                exit();
            }

            if (!array_key_exists('oMolliePayment', $_SESSION) || !($_SESSION['oMolliePayment'] instanceof \Mollie\Api\Resources\Order)) {
                $hash = $this->generateHash($order);
                //$_SESSION['cMollieHash'] = $hash;
                $orderData = $this->getOrderData($order, $hash);
                $oMolliePayment = self::API()->orders->create($orderData);
                $this->updateHash($hash, $oMolliePayment->id);
                $_SESSION['oMolliePayment'] = $oMolliePayment;
            } else {
                $oMolliePayment = $_SESSION['oMolliePayment'];
            }
            $logData .= '$' . $oMolliePayment->id;
            $this->doLog('Mollie Create Payment Redirect: ' . $oMolliePayment->getCheckoutUrl() . "<br/><pre>" . print_r($oMolliePayment, 1) . "</pre>", $logData, LOGLEVEL_DEBUG);
            Payment::updateFromPayment($oMolliePayment, $order->kBestellung, md5(trim($hash, '_')));
            Shop::Smarty()->assign('oMolliePayment', $oMolliePayment);
            if (!$this->duringCheckout) {
                Session::getInstance()->cleanUp();
            }
            header('Location: ' . $oMolliePayment->getCheckoutUrl());
            unset($_SESSION['oMolliePayment']);
            echo "<a href='{$oMolliePayment->getCheckoutUrl()}'>redirect to payment ...</a>";
            exit();
        } catch (ApiException $e) {
            $this->doLog("Create Payment Error: " . $e->getMessage() . '<br/><pre>' . print_r($orderData, 1) . '</pre>', $logData, LOGLEVEL_ERROR);
            header('Location: ' . Shop::getURL() . '/bestellvorgang.php?editZahlungsart=1&mollieStatus=failed');
            echo "<a href='" . Shop::getURL() . '/bestellvorgang.php?editZahlungsart=1&mollieStatus=failed' . "'>redirect...</a>";
            exit();
        }
    }

    /**
     * @return MollieApiClient
     * @throws ApiException
     * @throws IncompatiblePlatform
     */
    public static function API()
    {
        Helper::init();
        if (self::$_mollie === null) {
            self::$_mollie = new MollieApiClient();
            self::$_mollie->setApiKey(Helper::getSetting('api_key'));
            self::$_mollie->addVersionString("JTL-Shop/" . JTL_VERSION . '.' . JTL_MINOR_VERSION);
            self::$_mollie->addVersionString("ws_mollie/" . Helper::oPlugin()->nVersion);
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

        $_currencyFactor = (float)$order->Waehrung->fFaktor;

        $data = [
            'locale' => $locale ?: 'de_DE',
            'amount' => (object)[
                'currency' => $order->Waehrung->cISO,
                //'value' => number_format($order->fGesamtsummeKundenwaehrung, 2, '.', ''),
                // runden auf 5 Rappen berücksichtigt
                'value' => number_format($this->optionaleRundung($order->fGesamtsumme * $_currencyFactor), 2, '.', ''),
            ],
            'orderNumber' => utf8_encode($order->cBestellNr),
            'lines' => [],
            'billingAddress' => new stdClass(),

            'redirectUrl' => (int)$this->duringCheckout ? Shop::getURL() . '/bestellabschluss.php?mollie=' . md5(trim($hash, '_')) : $this->getReturnURL($order),
            'webhookUrl' => $this->getNotificationURL($hash), // . '&hash=' . md5(trim($hash, '_')),
        ];

        if (static::MOLLIE_METHOD !== '') {
            $data['method'] = static::MOLLIE_METHOD;
        }

        if (static::MOLLIE_METHOD === \Mollie\Api\Types\PaymentMethod::CREDITCARD && array_key_exists('mollieCardToken', $_SESSION)) {
            $data['payment'] = new stdClass();
            $data['payment']->cardToken = trim($_SESSION['mollieCardToken']);
        }

        if ($organizationName = utf8_encode(trim($order->oRechnungsadresse->cFirma))) {
            $data['billingAddress']->organizationName = $organizationName;
        }
        $data['billingAddress']->title = utf8_encode($order->oRechnungsadresse->cAnrede === 'm' ? Shop::Lang()->get('mr') : Shop::Lang()->get('mrs'));
        $data['billingAddress']->givenName = utf8_encode($order->oRechnungsadresse->cVorname);
        $data['billingAddress']->familyName = utf8_encode($order->oRechnungsadresse->cNachname);
        $data['billingAddress']->email = utf8_encode($order->oRechnungsadresse->cMail);
        $data['billingAddress']->streetAndNumber = utf8_encode($order->oRechnungsadresse->cStrasse . ' ' . $order->oRechnungsadresse->cHausnummer);
        $data['billingAddress']->postalCode = utf8_encode($order->oRechnungsadresse->cPLZ);
        $data['billingAddress']->city = utf8_encode($order->oRechnungsadresse->cOrt);
        $data['billingAddress']->country = $order->oRechnungsadresse->cLand;

        if ($order->Lieferadresse != null) {
            $data['shippingAddress'] = new stdClass();
            if ($organizationName = utf8_encode(trim($order->Lieferadresse->cFirma))) {
                $data['shippingAddress']->organizationName = $organizationName;
            }
            $data['shippingAddress']->title = utf8_encode($order->Lieferadresse->cAnrede === 'm' ? Shop::Lang()->get('mr') : Shop::Lang()->get('mrs'));
            $data['shippingAddress']->givenName = utf8_encode($order->Lieferadresse->cVorname);
            $data['shippingAddress']->familyName = utf8_encode($order->Lieferadresse->cNachname);
            $data['shippingAddress']->email = utf8_encode($order->oRechnungsadresse->cMail);
            $data['shippingAddress']->streetAndNumber = utf8_encode($order->Lieferadresse->cStrasse . ' ' . $order->Lieferadresse->cHausnummer);
            $data['shippingAddress']->postalCode = utf8_encode($order->Lieferadresse->cPLZ);
            $data['shippingAddress']->city = utf8_encode($order->Lieferadresse->cOrt);
            $data['shippingAddress']->country = $order->Lieferadresse->cLand;
        }


        /** @var WarenkorbPos $oPosition */
        foreach ($order->Positionen as $oPosition) {

            // EUR => 1
            $_netto = round($oPosition->fPreis, 2);
            $_vatRate = (float)$oPosition->fMwSt / 100;
            $_amount = (float)$oPosition->nAnzahl;

            $unitPriceNetto = round(($_currencyFactor * $_netto), 2);
            $unitPrice = round($unitPriceNetto * (1 + $_vatRate), 2);
            $totalAmount = round($_amount * $unitPrice, 2);
            $vatAmount = round($totalAmount - ($totalAmount / (1 + $_vatRate)), 2);

            $line = new stdClass();
            $line->name = utf8_encode($oPosition->cName);
            $line->quantity = $oPosition->nAnzahl;
            $line->unitPrice = (object)[
                'value' => number_format($unitPrice, 2, '.', ''),
                'currency' => $order->Waehrung->cISO,
            ];
            $line->totalAmount = (object)[
                'value' => number_format($totalAmount, 2, '.', ''),
                'currency' => $order->Waehrung->cISO,
            ];
            $line->vatRate = "{$oPosition->fMwSt}";

            $line->vatAmount = (object)[
                'value' => number_format($vatAmount, 2, '.', ''),
                'currency' => $order->Waehrung->cISO,
            ];

            switch ((int)$oPosition->nPosTyp) {
                case (int)C_WARENKORBPOS_TYP_GRATISGESCHENK:
                case (int)C_WARENKORBPOS_TYP_ARTIKEL:
                    $line->type = OrderLineType::TYPE_PHYSICAL;
                    $line->sku = $oPosition->cArtNr;
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
            $line = new stdClass();
            $line->type = OrderLineType::TYPE_STORE_CREDIT;
            $line->name = 'Guthaben';
            $line->quantity = 1;
            $line->unitPrice = (object)[
                'value' => number_format($order->Waehrung->fFaktor * $order->fGuthaben, 2, '.', ''),
                'currency' => $order->Waehrung->cISO,
            ];
            $line->unitPrice = (object)[
                'value' => number_format($order->Waehrung->fFaktor * $order->fGuthaben, 2, '.', ''),
                'currency' => $order->Waehrung->cISO,
            ];
            $line->totalAmount = (object)[
                'value' => number_format($order->Waehrung->fFaktor * $order->fGuthaben, 2, '.', ''),
                'currency' => $order->Waehrung->cISO,
            ];
            $line->vatRate = "0.00";
            $line->vatAmount = (object)[
                'value' => number_format(0, 2, '.', ''),
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
                $line = new stdClass();
                $line->type = $diff > 0 ? OrderLineType::TYPE_SURCHARGE : OrderLineType::TYPE_DISCOUNT;
                $line->name = 'Rundungsausgleich';
                $line->quantity = 1;
                $line->unitPrice = (object)[
                    'value' => number_format($diff, 2, '.', ''),
                    'currency' => $order->Waehrung->cISO,
                ];
                $line->unitPrice = (object)[
                    'value' => number_format($diff, 2, '.', ''),
                    'currency' => $order->Waehrung->cISO,
                ];
                $line->totalAmount = (object)[
                    'value' => number_format($diff, 2, '.', ''),
                    'currency' => $order->Waehrung->cISO,
                ];
                $line->vatRate = "0.00";
                $line->vatAmount = (object)[
                    'value' => number_format(0, 2, '.', ''),
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
            case "ger":
                if ($country === "AT") {
                    return "de_AT";
                }
                if ($country === "CH") {
                    return "de_CH";
                }
                return "de_DE";
            case "fre":
                if ($country === "BE") {
                    return "fr_BE";
                }
                return "fr_FR";
            case "dut":
                if ($country === "BE") {
                    return "nl_BE";
                }
                return "nl_NL";
            case "spa":
                return "es_ES";
            case "ita":
                return "it_IT";
            case "pol":
                return "pl_PL";
            case "hun":
                return "hu_HU";
            case "por":
                return "pt_PT";
            case "nor":
                return "nb_NO";
            case "swe":
                return "sv_SE";
            case "fin":
                return "fi_FI";
            case "dan":
                return "da_DK";
            case "ice":
                return "is_IS";
            default:
                return "en_US";
        }
    }

    public function optionaleRundung($gesamtsumme)
    {
        $conf = Shop::getSettings([CONF_KAUFABWICKLUNG]);
        if (isset($conf['kaufabwicklung']['bestellabschluss_runden5']) && $conf['kaufabwicklung']['bestellabschluss_runden5'] == 1) {
            $waehrung = isset($_SESSION['Waehrung']) ? $_SESSION['Waehrung'] : null;
            if ($waehrung === null || !isset($waehrung->kWaehrung)) {
                $waehrung = Shop::DB()->select('twaehrung', 'cStandard', 'Y');
            }
            $faktor = $waehrung->fFaktor;
            $gesamtsumme *= $faktor;

            // simplification. see https://de.wikipedia.org/wiki/Rundung#Rappenrundung
            $gesamtsumme = round($gesamtsumme * 20) / 20;
            $gesamtsumme /= $faktor;
        }

        return $gesamtsumme;
    }

    public function updateHash($hash, $orderID)
    {
        $hash = trim($hash, '_');
        $_upd = new stdClass();
        $_upd->cNotifyID = $orderID;
        return Shop::DB()->update('tzahlungsession', 'cZahlungsID', $hash, $_upd);
    }

    /**
     * @param Bestellung $order
     * @param string $hash
     * @param array $args
     */
    public function handleNotification($order, $hash, $args)
    {

        $logData = '#' . $order->kBestellung . "§" . $order->cBestellNr;
        $this->doLog('JTLMollie::handleNotification<br/><pre>' . print_r([$hash, $args], 1) . '</pre>', $logData, LOGLEVEL_DEBUG);

        try {

            $oMolliePayment = self::API()->orders->get($args['id'], ['embed' => 'payments']);
            Mollie::handleOrder($oMolliePayment, $order->kBestellung);

        } catch (Exception $e) {
            $this->doLog('JTLMollie::handleNotification: ' . $e->getMessage(), $logData);
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
        $result = false;
        try {
            if ($oZahlungSession = self::getZahlungSession(md5($hash))) {
                if ((int)$oZahlungSession->kBestellung <= 0) {

                    $logData = '$' . $args['id'];
                    $GLOBALS['mollie_notify_lock'] = new \ws_mollie\ExclusiveLock('mollie_' . $args['id'], PFAD_ROOT . PFAD_COMPILEDIR);
                    if ($GLOBALS['mollie_notify_lock']->lock()) {
                        $this->doLog("JTLMollie::finalizeOrder::locked ({$args['id']})", $logData, LOGLEVEL_DEBUG);
                    } else {
                        $this->doLog("JTLMollie::finalizeOrder::locked failed ({$args['id']})", $logData, LOGLEVEL_ERROR);
                    }

                    $oOrder = self::API()->orders->get($args['id'], ['embed' => 'payments']);
                    $result = in_array($oOrder->status, [OrderStatus::STATUS_PAID, OrderStatus::STATUS_AUTHORIZED, OrderStatus::STATUS_PENDING, OrderStatus::STATUS_COMPLETED]);
                    $this->doLog('JTLMollie::finalizeOrder (' . ($result ? 'true' : 'false') . ')<br/><pre>' . print_r([$hash, $args, $oOrder], 1) . '</pre>', $logData, LOGLEVEL_DEBUG);
                    //Payment::updateFromPayment($oMolliePayment, $order->kBestellung);
                }
            }
        } catch (Exception $e) {
            $this->doLog('JTLMollie::finalizeOrder: ' . $e->getMessage(), "#" . $hash);
        }
        return $result;
    }

    public static function getZahlungSession($hash)
    {
        return Shop::DB()->executeQueryPrepared("SELECT * FROM tzahlungsession WHERE MD5(cZahlungsID) = :cZahlungsID", [':cZahlungsID' => trim($hash, '_')], 1);
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
        /** @var Warenkorb $wk */
        $wk = $_SESSION['Warenkorb'];
        foreach ($wk->PositionenArr as $oPosition) {
            if ((int)$oPosition->nPosTyp === (int)C_WARENKORBPOS_TYP_ARTIKEL && $oPosition->Artikel && $oPosition->Artikel->cTeilbar === 'Y'
                && fmod($oPosition->nAnzahl, 1) !== 0.0) {
                return false;
            }
        }

        $locale = self::getLocale($_SESSION['cISOSprache'], $_SESSION['Kunde']->cLand);
        if (static::MOLLIE_METHOD !== '') {
            try {
                $method = self::PossiblePaymentMethods(static::MOLLIE_METHOD, $locale, $_SESSION['Kunde']->cLand, $_SESSION['Waehrung']->cISO, $wk->gibGesamtsummeWaren(true) * $_SESSION['Waehrung']->fFaktor);
                if ($method !== null) {

                    if ((int)$this->duringCheckout === 1 && !static::ALLOW_PAYMENT_BEFORE_ORDER) {
                        $this->doLog(static::MOLLIE_METHOD . " cannot be used for payment before order.");
                        return false;
                    }

                    $this->updatePaymentMethod($_SESSION['cISOSprache'], $method);
                    $this->cBild = $method->image->size2x;
                    return true;
                }
                return false;
            } catch (Exception $e) {
                $this->doLog('Method ' . static::MOLLIE_METHOD . ' not selectable:' . $e->getMessage());
                return false;
            }
        } else {
            $this->doLog("Global mollie PaymentMethod cannot be used for payments directly.");
        }
        return false;
    }

    /**
     * @param $method
     * @param $locale
     * @param $billingCountry
     * @param $currency
     * @param $amount
     * @return mixed|null
     * @throws ApiException
     * @throws IncompatiblePlatform
     */
    protected static function PossiblePaymentMethods($method, $locale, $billingCountry, $currency, $amount)
    {
        $key = md5(serialize([$locale, $billingCountry, $amount, $currency]));
        if (!array_key_exists($key, self::$_possiblePaymentMethods)) {
            self::$_possiblePaymentMethods[$key] = self::API()->methods->allActive(['amount' => ['currency' => $currency, 'value' => number_format($amount, 2, '.', '')], 'billingCountry' => $_SESSION['Kunde']->cLand, 'locale' => $locale, 'includeWallets' => 'applepay', 'include' => 'pricing,issuers', 'resource' => 'orders']);
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
        if (Helper::getSetting('paymentmethod_sync') === 'N') {
            return;
        }
        $size = Helper::getSetting('paymentmethod_sync');
        if ((!isset($this->cBild) || $this->cBild === '') && isset($method->image->$size)) {
            Shop::DB()->executeQueryPrepared("UPDATE tzahlungsart SET cBild = :cBild WHERE cModulId = :cModulId", [':cBild' => $method->image->$size, ':cModulId' => $this->cModulId], 3);
        }
        if ($za = Shop::DB()->executeQueryPrepared('SELECT kZahlungsart FROM tzahlungsart WHERE cModulID = :cModulID', [':cModulID' => $this->moduleID], 1)) {
            Shop::DB()->executeQueryPrepared("INSERT INTO tzahlungsartsprache (kZahlungsart, cISOSprache, cName, cGebuehrname, cHinweisText) VALUES (:kZahlungsart, :cISOSprache, :cName, :cGebuehrname, :cHinweisText) ON DUPLICATE KEY UPDATE cName = IF(cName = '',:cName1,cName), cHinweisTextShop = IF(cHinweisTextShop = '' || cHinweisTextShop IS NULL,:cHinweisTextShop,cHinweisTextShop);", [
                ':kZahlungsart' => (int)$za->kZahlungsart,
                ':cISOSprache' => $cISOSprache,
                ':cName' => utf8_decode($method->description),
                ':cGebuehrname' => '',
                ':cHinweisText' => '',
                ':cHinweisTextShop' => utf8_decode($method->description),
                'cName1' => $method->description,
            ], 3);
        }
    }

    /**
     * @param array $args_arr
     * @return bool
     */
    public function isValidIntern($args_arr = [])
    {
        if (Helper::getSetting("api_key")) {
            return true;
        }
        $this->doLog("isValdid failed: init failed or no API Key given. Try clear the Cache.");
        return false;
    }
}
