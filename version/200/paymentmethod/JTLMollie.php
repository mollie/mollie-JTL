<?php

use Mollie\Api\Exceptions\ApiException;
use ws_mollie\API;
use ws_mollie\Checkout\OrderCheckout;
use ws_mollie\Checkout\PaymentCheckout;
use ws_mollie\Helper;

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../class/Helper.php';
require_once __DIR__ . '/../../../../../modules/PaymentMethod.class.php';
require_once __DIR__ . '/../class/Traits/Plugin.php';

class JTLMollie extends PaymentMethod
{

    use \ws_mollie\Traits\Plugin;

    const MAX_EXPIRY_DAYS = 100;

    const ALLOW_PAYMENT_BEFORE_ORDER = false;

    /**
     * PaymentMethod identifier
     */
    const METHOD = "";

    /**
     * @var string
     * @deprecated
     */
    public $cBild;

    public function __construct($moduleID, $nAgainCheckout = 0)
    {
        parent::__construct($moduleID, $nAgainCheckout);
        $this->cModulId = "kPlugin_" . self::Plugin()->kPlugin . "_mollie{$moduleID}";
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
     * @return void
     */
    public function preparePaymentProcess($order)
    {

        parent::preparePaymentProcess($order);

        try {

            if ($this->duringCheckout) {
                $this->Log(sprintf("Zahlung vor Bestellabschluss nicht unterstützt (%s)!", $order->cBestellNr), LOGLEVEL_ERROR);
                return;
            }

            $payable = (float)$order->fGesamtsumme > 0;
            if (!$payable) {
                $this->Log(sprintf("Bestellung '%s': Gesamtsumme %.2f, keine Zahlung notwendig!", $order->cBestellNr, $order->fGesamtsumme), LOGLEVEL_NOTICE);
                return;
            }

            $paymentOptions = [];

            $api = array_key_exists($this->moduleID . '_api', self::Plugin()->oPluginEinstellungAssoc_arr) ? self::Plugin()->oPluginEinstellungAssoc_arr[$this->moduleID . '_api'] : 'order';

            $paymentOptions = array_merge($paymentOptions, $this->getPaymentOptions($order, $api));

            if ($api === 'payment') {
                $checkout = new PaymentCheckout($order);
                $payment = $checkout->create($paymentOptions);
                $url = $payment->getCheckoutUrl();
            } else {
                $checkout = new OrderCheckout($order);
                $mOrder = $checkout->create($paymentOptions);
                $url = $mOrder->getCheckoutUrl();
            }

            ifndef('MOLLIE_REDIRECT_DELAY', 3);
            $checkoutMode = self::Plugin()->oPluginEinstellungAssoc_arr['checkoutMode'];
            Shop::Smarty()->assign('redirect', $url)
                ->assign('checkoutMode', $checkoutMode);
            if ($checkoutMode === 'Y' && !headers_sent()) {
                header('Location: ' . $url);
            }
        } catch (Exception $e) {
            $this->Log('mollie::preparePaymentProcess: ' . $e->getMessage() . ' - ' . print_r(['cBestellNr' => $order->cBestellNr], 1), LOGLEVEL_ERROR);
            Shop::Smarty()->assign('oMollieException', $e);
        }
    }

    public function Log($msg, $data = null, $level = LOGLEVEL_NOTICE)
    {
        ZahlungsLog::add($this->moduleID, "[" . microtime(true) . " - " . $_SERVER['PHP_SELF'] . "] " . $msg, $data, $level);
        return $this;
    }

    public function getPaymentOptions(Bestellung $order, $apiType)
    {
        return [];
    }

    /**
     * @param Bestellung $order
     * @param string $hash
     * @param array $args
     */
    public function handleNotification($order, $hash, $args)
    {
        parent::handleNotification($order, $hash, $args);
        try {
            $orderId = $args['id'];
            $checkout = null;
            if (strpos($orderId, 'tr_') === 0) {
                $checkout = new PaymentCheckout($order);
            } else {
                $checkout = new OrderCheckout($order);
            }
            $checkout->handleNotification($hash);

        } catch (Exception $e) {
            $checkout->Log(sprintf("mollie::handleNotification: Fehler bei Bestellung '%s': %s\n%s", $order->cBestellNr, $e->getMessage(), print_r($args, 1)), LOGLEVEL_ERROR);
        }
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

        if (API::getMode()) {
            $selectable = trim(self::Plugin()->oPluginEinstellungAssoc_arr['test_api_key']) !== '';
        } else {
            $selectable = trim(self::Plugin()->oPluginEinstellungAssoc_arr['api_key']) !== '';
            if (!$selectable) {
                Jtllog::writeLog("Live API Key missing!");
            }
        }
        if ($selectable) {
            try {
                $locale = \ws_mollie\Checkout\Payment\Locale::getLocale(Session::getInstance()->Language()->getIso(), Session::getInstance()->Customer()->cLand);
                $amount = Session::getInstance()->Basket()->gibGesamtsummeWaren(true) * Session::getInstance()->Currency()->fFaktor;
                if ($amount <= 0) {
                    $amount = 0.01;
                }
                $selectable = self::isMethodPossible(
                    static::METHOD,
                    $locale,
                    Session::getInstance()->Customer()->cLand,
                    Session::getInstance()->Currency()->cISO,
                    $amount
                );
            } catch (Exception $e) {
                Helper::logExc($e);
                $selectable = false;
            }
        }
        return $selectable && parent::isSelectable();
    }

    /**
     * @param $method
     * @param $locale
     * @param $billingCountry
     * @param $currency
     * @param $amount
     * @return bool
     * @throws ApiException
     */
    protected static function isMethodPossible($method, $locale, $billingCountry, $currency, $amount)
    {

        $api = new API(API::getMode());

        if (!array_key_exists('mollie_possibleMethods', $_SESSION)) {
            $_SESSION['mollie_possibleMethods'] = [];
        }

        $key = md5(serialize([$locale, $billingCountry, $currency, $amount]));
        if (!array_key_exists($key, $_SESSION['mollie_possibleMethods'])) {
            $_SESSION['mollie_possibleMethods'][$key] = $api->Client()->methods->allActive([
                'locale' => $locale,
                'amount' => [
                    'currency' => $currency,
                    'value' => number_format($amount, 2, ".", "")
                ],
                'billingCountry' => $billingCountry,
                'resource' => 'orders',
                'includeWallets' => 'applepay',
            ]);
        }

        if ($method !== '') {
            foreach ($_SESSION['mollie_possibleMethods'][$key] as $m) {
                if ($m->id === $method) {
                    return true;
                }
            }
        } else {
            return true;
        }

        return false;

    }

    /**
     * @param array $args_arr
     * @return bool
     */
    public function isValidIntern($args_arr = [])
    {
        return $this->duringCheckout
            ? static::ALLOW_PAYMENT_BEFORE_ORDER && parent::isValidIntern($args_arr)
            : parent::isValidIntern($args_arr);
    }

    /**
     * @return int
     */
    public function getExpiryDays()
    {
        return (int)min(abs((int)Helper::oPlugin()->oPluginEinstellungAssoc_arr[$this->cModulId . '_dueDays']), static::MAX_EXPIRY_DAYS);
    }

    /**
     * @param $cISOSprache
     * @param $method
     * @deprecated
     * @noinspection PhpDeprecationInspection
     * @noinspection PhpDeprecationInspection
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
}
