<?php
/**
 * @copyright 2021 WebStollen GmbH
 * @link https://www.webstollen.de
 */

namespace ws_mollie\Checkout;

use Artikel;
use ArtikelHelper;
use Bestellung;
use EigenschaftWert;
use Exception;
use Jtllog;
use JTLMollie;
use Kunde;
use Mollie\Api\Exceptions\ApiException;
use Mollie\Api\Resources\BaseResource;
use Mollie\Api\Resources\Order;
use Mollie\Api\Resources\Refund;
use Mollie\Api\Types\OrderStatus;
use Mollie\Api\Types\PaymentStatus;
use PaymentMethod;
use RuntimeException;
use Session;
use Shop;
use stdClass;
use ws_mollie\API;
use ws_mollie\Checkout\Payment\Amount;
use ws_mollie\Helper;
use ws_mollie\Hook\Queue;
use ws_mollie\Model\Customer;
use ws_mollie\Model\Payment;
use ws_mollie\Traits\Plugin;
use ws_mollie\Traits\RequestData;
use ZahlungsLog;

abstract class AbstractCheckout
{
    use RequestData;
    use Plugin;

    protected static $localeLangs = [
        'ger' => ['lang' => 'de', 'country' => ['DE', 'AT', 'CH']],
        'fre' => ['lang' => 'fr', 'country' => ['BE', 'FR']],
        'dut' => ['lang' => 'nl', 'country' => ['BE', 'NL']],
        'spa' => ['lang' => 'es', 'country' => ['ES']],
        'ita' => ['lang' => 'it', 'country' => ['IT']],
        'pol' => ['lang' => 'pl', 'country' => ['PL']],
        'hun' => ['lang' => 'hu', 'country' => ['HU']],
        'por' => ['lang' => 'pt', 'country' => ['PT']],
        'nor' => ['lang' => 'nb', 'country' => ['NO']],
        'swe' => ['lang' => 'sv', 'country' => ['SE']],
        'fin' => ['lang' => 'fi', 'country' => ['FI']],
        'dan' => ['lang' => 'da', 'country' => ['DK']],
        'ice' => ['lang' => 'is', 'country' => ['IS']],
        'eng' => ['lang' => 'en', 'country' => ['GB', 'US']],
    ];
    /**
     * @var null|\Mollie\Api\Resources\Customer
     */
    protected $customer;
    /**
     * @var string
     */
    private $hash;
    /**
     * @var API
     */
    private $api;
    /**
     * @var JTLMollie
     */
    private $paymentMethod;
    /**
     * @var Bestellung
     */
    private $oBestellung;
    /**
     * @var Payment
     */
    private $model;

    /**
     * AbstractCheckout constructor.
     * @param Bestellung $oBestellung
     * @param null       $api
     */
    public function __construct(Bestellung $oBestellung, $api = null)
    {
        $this->api         = $api;
        $this->oBestellung = $oBestellung;
    }

    /**
     * @param int  $kBestellung
     * @param bool $checkZA
     * @return bool
     */
    public static function isMollie($kBestellung, $checkZA = false)
    {
        if ($checkZA) {
            $res = Shop::DB()->executeQueryPrepared('SELECT * FROM tzahlungsart WHERE cModulId LIKE :cModulId AND kZahlungsart = :kZahlungsart', [
                ':kZahlungsart' => $kBestellung,
                ':cModulId'     => 'kPlugin_' . self::Plugin()->kPlugin . '_%'
            ], 1);

            return (bool)$res;
        }

        return ($res = Shop::DB()->executeQueryPrepared('SELECT kId FROM xplugin_ws_mollie_payments WHERE kBestellung = :kBestellung;', [
                ':kBestellung' => $kBestellung,
            ], 1)) && $res->kId;
    }

    public static function finalizeOrder($sessionHash, $id, $test = false)
    {
        try {
            if ($paymentSession = Shop::DB()->select('tzahlungsession', 'cZahlungsID', $sessionHash)) {
                if (session_id() !== $paymentSession->cSID) {
                    session_destroy();
                    session_id($paymentSession->cSID);
                    $session = Session::getInstance(true, true);
                } else {
                    $session = Session::getInstance(false);
                }

                if ((!isset($paymentSession->nBezahlt) || !$paymentSession->nBezahlt)
                    && (!isset($paymentSession->kBestellung) || !$paymentSession->kBestellung)
                    && isset($_SESSION['Warenkorb']->PositionenArr)
                    && count($_SESSION['Warenkorb']->PositionenArr)) {
                    $paymentSession->cNotifyID = $id;
                    $paymentSession->dNotify   = 'now()';
                    Shop::DB()->update('tzahlungsession', 'cZahlungsID', $sessionHash, $paymentSession);

                    $api = new API($test);
                    if (strpos($id, 'tr_') === 0) {
                        $mollie = $api->Client()->payments->get($id);
                    } else {
                        $mollie = $api->Client()->orders->get($id, ['embed' => 'payments']);
                    }

                    if (in_array($mollie->status, [OrderStatus::STATUS_PENDING, OrderStatus::STATUS_AUTHORIZED, OrderStatus::STATUS_PAID], true)) {
                        require_once PFAD_ROOT . PFAD_INCLUDES . 'bestellabschluss_inc.php';
                        require_once PFAD_ROOT . PFAD_INCLUDES . 'mailTools.php';
                        $order = finalisiereBestellung();
                        $session->cleanUp();
                        $paymentSession->nBezahlt     = 1;
                        $paymentSession->dZeitBezahlt = 'now()';
                    } else {
                        throw new Exception('Mollie Status invalid: ' . $mollie->status . '\n' . print_r([$sessionHash, $id], 1));
                    }

                    if ($order->kBestellung) {
                        $paymentSession->kBestellung = $order->kBestellung;
                        Shop::DB()->update('tzahlungsession', 'cZahlungsID', $sessionHash, $paymentSession);

                        try {
                            $checkout = self::fromID($id, false, $order);
                        } catch (Exception $e) {
                            if (strpos($id, 'tr_') === 0) {
                                $checkoutClass = PaymentCheckout::class;
                            } else {
                                $checkoutClass = OrderCheckout::class;
                            }
                            $checkout = new $checkoutClass($order, $api);
                        }
                        $checkout->setMollie($mollie)->updateModel()->saveModel();
                        $checkout->updateOrderNumber();
                        $checkout->handleNotification($sessionHash);
                    }
                } else {
                    Jtllog::writeLog(sprintf('PaymentSession bereits bezahlt: %s - ID: %s => Queue', $sessionHash, $id), JTLLOG_LEVEL_NOTICE);
                    Queue::saveToQueue($id, $id, 'webhook');
                }
            } else {
                Jtllog::writeLog(sprintf('PaymentSession nicht gefunden: %s - ID: %s => Queue', $sessionHash, $id), JTLLOG_LEVEL_NOTICE);
                Queue::saveToQueue($id, $id, 'webhook');
            }
        } catch (Exception $e) {
            Helper::logExc($e);
        }
    }

    /**
     * @param string          $id
     * @param bool            $bFill
     * @param null|Bestellung $order
     * @throws RuntimeException
     * @return OrderCheckout|PaymentCheckout
     */
    public static function fromID($id, $bFill = true, Bestellung $order = null)
    {
        if (($model = Payment::fromID($id))) {
            return static::fromModel($model, $bFill, $order);
        }

        throw new RuntimeException(sprintf('Error loading Order: %s', $id));
    }

    /**
     * @param Payment         $model
     * @param bool            $bFill
     * @param null|Bestellung $order
     * @return OrderCheckout|PaymentCheckout
     */
    public static function fromModel($model, $bFill = true, Bestellung $order = null)
    {
        if (!$model) {
            throw new RuntimeException(sprintf('Error loading Order for Model: %s', print_r($model, 1)));
        }

        $oBestellung = $order;
        if (!$order) {
            $oBestellung = new Bestellung($model->kBestellung, $bFill);
            if (!$oBestellung->kBestellung) {
                throw new RuntimeException(sprintf('Error loading Bestellung: %s', $model->kBestellung));
            }
        }

        if (strpos($model->kID, 'tr_') !== false) {
            $self = new PaymentCheckout($oBestellung);
        } else {
            $self = new OrderCheckout($oBestellung);
        }

        $self->setModel($model);

        return $self;
    }

    /**
     * @return bool
     */
    public function saveModel()
    {
        return $this->getModel()->save();
    }

    /**
     * @return Payment
     */
    public function getModel()
    {
        if (!$this->model) {
            $this->model = Payment::fromID($this->oBestellung->kBestellung, 'kBestellung');
        }

        return $this->model;
    }

    /**
     * @param $model
     * @return $this
     */
    protected function setModel($model)
    {
        if (!$this->model) {
            $this->model = $model;
        } else {
            throw new RuntimeException('Model already set.');
        }

        return $this;
    }

    /**
     * @param null $hash
     * @throws Exception
     */
    public function handleNotification($hash = null)
    {
        if (!$this->getHash()) {
            $this->getModel()->cHash = $hash;
        }

        $this->updateModel()->saveModel();
        if (!$this->getBestellung()->dBezahltDatum || $this->getBestellung()->dBezahltDatum === '0000-00-00') {
            if ($incoming = $this->getIncomingPayment()) {
                $this->PaymentMethod()->addIncomingPayment($this->getBestellung(), $incoming);

                $this->PaymentMethod()->setOrderStatusToPaid($this->getBestellung());
                static::makeFetchable($this->getBestellung(), $this->getModel());
                $this->PaymentMethod()->deletePaymentHash($this->getHash());
                $this->Log(sprintf("Checkout::handleNotification: Bestellung '%s' als bezahlt markiert: %.2f %s", $this->getBestellung()->cBestellNr, (float)$incoming->fBetrag, $incoming->cISO));

                $oZahlungsart = Shop::DB()->selectSingleRow('tzahlungsart', 'cModulId', $this->PaymentMethod()->moduleID);
                if ($oZahlungsart && (int)$oZahlungsart->nMailSenden === 1) {
                    require_once PFAD_ROOT . 'includes/mailTools.php';
                    $this->PaymentMethod()->sendConfirmationMail($this->getBestellung());
                }
                if (!$this->completlyPaid()) {
                    $this->Log(sprintf("Checkout::handleNotification: Bestellung '%s': nicht komplett bezahlt: %.2f %s", $this->getBestellung()->cBestellNr, (float)$incoming->fBetrag, $incoming->cISO), LOGLEVEL_ERROR);
                }
            }
        }
    }

    /**
     * @return string
     */
    public function getHash()
    {
        if ($this->getModel()->cHash) {
            return $this->getModel()->cHash;
        }
        if (!$this->hash) {
            $this->hash = $this->PaymentMethod()->generateHash($this->oBestellung);
        }

        return $this->hash;
    }

    /**
     * @return JTLMollie
     */
    public function PaymentMethod()
    {
        if (!$this->paymentMethod) {
            include_once PFAD_ROOT . PFAD_INCLUDES . 'modules/PaymentMethod.class.php';
            if ($this->getBestellung()->Zahlungsart && strpos($this->getBestellung()->Zahlungsart->cModulId, "kPlugin_{$this::Plugin()->kPlugin}_") !== false) {
                try {
                    $this->paymentMethod = PaymentMethod::create($this->getBestellung()->Zahlungsart->cModulId);
                } catch (Exception $e) {
                    $this->paymentMethod = PaymentMethod::create("kPlugin_{$this::Plugin()->kPlugin}_mollie");
                }
            } else {
                $this->paymentMethod = PaymentMethod::create("kPlugin_{$this::Plugin()->kPlugin}_mollie");
            }
        }

        return $this->paymentMethod;
    }

    /**
     * @return Bestellung
     */
    public function getBestellung()
    {
        if (!$this->oBestellung && $this->getModel()->kBestellung) {
            $this->oBestellung = new Bestellung($this->getModel()->kBestellung, true);
        }

        return $this->oBestellung;
    }

    /**
     * @return $this
     */
    public function updateModel()
    {
        if ($this->getMollie()) {
            $this->getModel()->kID       = $this->getMollie()->id;
            $this->getModel()->cLocale   = $this->getMollie()->locale;
            $this->getModel()->fAmount   = (float)$this->getMollie()->amount->value;
            $this->getModel()->cMethod   = $this->getMollie()->method;
            $this->getModel()->cCurrency = $this->getMollie()->amount->currency;
            $this->getModel()->cStatus   = $this->getMollie()->status;
            if ($this->getMollie()->amountRefunded) {
                $this->getModel()->fAmountRefunded = $this->getMollie()->amountRefunded->value;
            }
            if ($this->getMollie()->amountCaptured) {
                $this->getModel()->fAmountCaptured = $this->getMollie()->amountCaptured->value;
            }
            $this->getModel()->cMode        = $this->getMollie()->mode ?: null;
            $this->getModel()->cRedirectURL = $this->getMollie()->redirectUrl;
            $this->getModel()->cWebhookURL  = $this->getMollie()->webhookUrl;
            $this->getModel()->cCheckoutURL = $this->getMollie()->getCheckoutUrl();
        }

        $this->getModel()->kBestellung  = $this->getBestellung()->kBestellung;
        $this->getModel()->cOrderNumber = $this->getBestellung()->cBestellNr;
        $this->getModel()->cHash        = trim($this->getHash(), '_');

        $this->getModel()->bSynced = $this->getModel()->bSynced !== null ? $this->getModel()->bSynced : Helper::getSetting('onlyPaid') !== 'Y';

        return $this;
    }

    /**
     * @param false $force
     * @return null|\Mollie\Api\Resources\Payment|Order
     */
    abstract public function getMollie($force = false);

    /**
     * @return stdClass
     */
    abstract public function getIncomingPayment();

    /**
     * @param Bestellung $oBestellung
     * @param Payment    $model
     * @return bool
     */
    public static function makeFetchable(Bestellung $oBestellung, Payment $model)
    {
        // TODO: force ?
        if ($oBestellung->cAbgeholt === 'Y' && !$model->bSynced) {
            Shop::DB()->update('tbestellung', 'kBestellung', $oBestellung->kBestellung, (object)['cAbgeholt' => 'N']);
        }
        $model->bSynced = true;

        try {
            return $model->save();
        } catch (Exception $e) {
            Jtllog::writeLog(sprintf('Fehler beim speichern des Models: %s / Bestellung: %s', $model->kID, $oBestellung->cBestellNr));
        }

        return false;
    }

    public function Log($msg, $level = LOGLEVEL_NOTICE)
    {
        try {
            $data = '';
            if ($this->getBestellung()) {
                $data .= '#' . $this->getBestellung()->kBestellung;
            }
            if ($this->getMollie()) {
                $data .= '$' . $this->getMollie()->id;
            }
            ZahlungsLog::add($this->PaymentMethod()->moduleID, '[' . microtime(true) . ' - ' . $_SERVER['PHP_SELF'] . '] ' . $msg, $data, $level);
        } catch (Exception $e) {
            Jtllog::writeLog('Mollie Log failed: ' . $e->getMessage() . '; Previous Log: ' . print_r([$msg, $level, $data], 1));
        }

        return $this;
    }

    /**
     * @return bool
     */
    public function completlyPaid()
    {
        if ($row = Shop::DB()->executeQueryPrepared('SELECT SUM(fBetrag) as fBetragSumme FROM tzahlungseingang WHERE kBestellung = :kBestellung', [
            ':kBestellung' => $this->getBestellung()->kBestellung
        ], 1)) {
            return $row->fBetragSumme >= round($this->getBestellung()->fGesamtsumme * $this->getBestellung()->fWaehrungsFaktor, 2);
        }

        return false;
    }

    /**
     * @param $kBestellung
     * * @throws RuntimeException
     * @return OrderCheckout|PaymentCheckout
     */
    public static function fromBestellung($kBestellung)
    {
        if ($model = Payment::fromID($kBestellung, 'kBestellung')) {
            return static::fromModel($model);
        }

        throw new RuntimeException(sprintf('Error loading Order for Bestellung: %s', $kBestellung));
    }

    public static function sendReminders()
    {
        $reminder = (int)self::Plugin()->oPluginEinstellungAssoc_arr['reminder'];

        if (!$reminder) {
            return;
        }

        $sql = 'SELECT p.kID FROM xplugin_ws_mollie_payments p JOIN tbestellung b ON b.kBestellung = p.kBestellung '
            . "WHERE (p.dReminder IS NULL OR p.dReminder = '0000-00-00 00:00:00') "
            . 'AND p.dCreatedAt < NOW() - INTERVAL :d MINUTE AND p.dCreatedAt > NOW() - INTERVAL 7 DAY '
            . "AND p.cStatus IN ('created','open', 'expired', 'failed', 'canceled') AND NOT b.cStatus = '-1'";

        $remindables = Shop::DB()->executeQueryPrepared($sql, [
            ':d' => $reminder
        ], 2);
        foreach ($remindables as $remindable) {
            try {
                self::sendReminder($remindable->kID);
            } catch (Exception $e) {
                Jtllog::writeLog('AbstractCheckout::sendReminders: ' . $e->getMessage());
            }
        }
    }

    /**
     * @param $kID
     * @return bool
     */
    public static function sendReminder($kID)
    {
        $checkout = self::fromID($kID);
        $return   = true;

        if (!$checkout->getBestellung()->kBestellung || (int)$checkout->getBestellung()->cStatus > BESTELLUNG_STATUS_IN_BEARBEITUNG || (int)$checkout->getBestellung()->cStatus < 0) {
            return $return;
        }


        try {
            $repayURL = Shop::getURL() . '/?m_pay=' . md5($checkout->getModel()->kID . '-' . $checkout->getBestellung()->kBestellung);

            $data         = new stdClass();
            $data->tkunde = new Kunde($checkout->getBestellung()->oKunde->kKunde);
            if ($data->tkunde->kKunde) {
                $data->Bestellung = $checkout->getBestellung();
                $data->PayURL     = $repayURL;
                $data->Amount     = gibPreisStringLocalized($checkout->getModel()->fAmount, $checkout->getBestellung()->Waehrung); //Preise::getLocalizedPriceString($order->getAmount(), Currency::fromISO($order->getCurrency()), false);

                require_once PFAD_ROOT . PFAD_INCLUDES . 'mailTools.php';

                $mail          = new stdClass();
                $mail->toEmail = $data->tkunde->cMail;
                $mail->toName  = trim((isset($data->tKunde->cVorname)
                        ? $data->tKunde->cVorname
                        : '') . ' ' . (
                            isset($data->tKunde->cNachname)
                        ? $data->tKunde->cNachname
                        : ''
                        )) ?: $mail->toEmail;
                $data->mail = $mail;
                if (!($sentMail = sendeMail('kPlugin_' . self::Plugin()->kPlugin . '_zahlungserinnerung', $data))) {
                    $checkout->Log(sprintf("Zahlungserinnerung konnte nicht versand werden: %s\n%s", isset($sentMail->cFehler) ?: print_r($sentMail, 1), print_r($data, 1)), LOGLEVEL_ERROR);
                    $return = false;
                } else {
                    $checkout->Log(sprintf('Zahlungserinnerung für %s verschickt.', $checkout->getBestellung()->cBestellNr));
                }
            } else {
                $checkout->Log("Kunde '{$checkout->getBestellung()->oKunde->kKunde}' nicht gefunden.", LOGLEVEL_ERROR);
            }
        } catch (Exception $e) {
            $checkout->Log(sprintf('AbstractCheckout::sendReminder: Zahlungserinnerung für %s fehlgeschlagen: %s', $checkout->getBestellung()->cBestellNr, $e->getMessage()));
            $return = false;
        }
        $checkout->getModel()->dReminder = date('Y-m-d H:i:s');
        $checkout->getModel()->save();

        return $return;
    }

    /**
     * @param AbstractCheckout $checkout
     * @throws ApiException
     * @return BaseResource|Refund
     */
    public static function refund(self $checkout)
    {
        if ($checkout->getMollie()->resource === 'order') {
            /** @var Order $order */
            $order = $checkout->getMollie();
            if (in_array($order->status, [OrderStatus::STATUS_CANCELED, OrderStatus::STATUS_EXPIRED, OrderStatus::STATUS_CREATED], true)) {
                throw new RuntimeException('Bestellung kann derzeit nicht zurückerstattet werden.');
            }
            $refund = $order->refundAll();
            $checkout->Log(sprintf('Bestellung wurde manuell zurückerstattet: %s', $refund->id));

            return $refund;
        }
        if ($checkout->getMollie()->resource === 'payment') {
            /** @var \Mollie\Api\Resources\Payment $payment */
            $payment = $checkout->getMollie();
            if (in_array($payment->status, [PaymentStatus::STATUS_CANCELED, PaymentStatus::STATUS_EXPIRED, PaymentStatus::STATUS_OPEN], true)) {
                throw new RuntimeException('Zahlung kann derzeit nicht zurückerstattet werden.');
            }
            $refund = $checkout->API()->Client()->payments->refund($checkout->getMollie(), ['amount' => $checkout->getMollie()->amount]);
            $checkout->Log(sprintf('Zahlung wurde manuell zurückerstattet: %s', $refund->id));

            return $refund;
        }

        throw new RuntimeException(sprintf('Unbekannte Resource: %s', $checkout->getMollie()->resource));
    }

    /**
     * @return API
     */
    public function API()
    {
        if (!$this->api) {
            if ($this->getModel()->kID) {
                $this->api = new API($this->getModel()->cMode === 'test');
            } else {
                $this->api = new API(API::getMode());
            }
        }

        return $this->api;
    }

    /**
     * @param $checkout
     * @throws ApiException
     * @return \Mollie\Api\Resources\Payment|Order
     */
    public static function cancel($checkout)
    {
        if ($checkout instanceof OrderCheckout) {
            return OrderCheckout::cancel($checkout);
        }
        if ($checkout instanceof PaymentCheckout) {
            return PaymentCheckout::cancel($checkout);
        }

        throw new RuntimeException('AbstractCheckout::cancel: Invalid Checkout!');
    }

    public static function getLocales()
    {
        $locales = [
            'en_US',
            'nl_NL',
            'nl_BE',
            'fr_FR',
            'fr_BE',
            'de_DE',
            'de_AT',
            'de_CH',
            'es_ES',
            'ca_ES',
            'pt_PT',
            'it_IT',
            'nb_NO',
            'sv_SE',
            'fi_FI',
            'da_DK',
            'is_IS',
            'hu_HU',
            'pl_PL',
            'lv_LV',
            'lt_LT',];

        $laender     = [];
        $shopLaender = Shop::DB()->executeQuery('SELECT cLaender FROM tversandart', 2);
        foreach ($shopLaender as $sL) {
            $laender = array_merge(explode(' ', $sL->cLaender));
        }
        $laender = array_unique($laender);

        $result       = [];
        $shopSprachen = Shop::DB()->executeQuery('SELECT * FROM tsprache', 2);
        foreach ($shopSprachen as $sS) {
            foreach ($laender as $land) {
                $result[] = static::getLocale($sS->cISO, $land);
            }
        }

        return array_intersect(array_unique($result), $locales);
    }

    public static function getLocale($cISOSprache = null, $country = null)
    {
        if ($cISOSprache === null) {
            $cISOSprache = gibStandardsprache()->cISO;
        }
        if (array_key_exists($cISOSprache, self::$localeLangs)) {
            $locale = self::$localeLangs[$cISOSprache]['lang'];
            if ($country && is_array(self::$localeLangs[$cISOSprache]['country']) && in_array($country, self::$localeLangs[$cISOSprache]['country'], true)) {
                $locale .= '_' . strtoupper($country);
            } else {
                $locale .= '_' . self::$localeLangs[$cISOSprache]['country'][0];
            }

            return $locale;
        }

        return self::Plugin()->oPluginEinstellungAssoc_arr['fallbackLocale'];
    }

    public static function getCurrencies()
    {
        $currencies = ['AED' => 'AED - United Arab Emirates dirham',
            'AFN'            => 'AFN - Afghan afghani',
            'ALL'            => 'ALL - Albanian lek',
            'AMD'            => 'AMD - Armenian dram',
            'ANG'            => 'ANG - Netherlands Antillean guilder',
            'AOA'            => 'AOA - Angolan kwanza',
            'ARS'            => 'ARS - Argentine peso',
            'AUD'            => 'AUD - Australian dollar',
            'AWG'            => 'AWG - Aruban florin',
            'AZN'            => 'AZN - Azerbaijani manat',
            'BAM'            => 'BAM - Bosnia and Herzegovina convertible mark',
            'BBD'            => 'BBD - Barbados dollar',
            'BDT'            => 'BDT - Bangladeshi taka',
            'BGN'            => 'BGN - Bulgarian lev',
            'BHD'            => 'BHD - Bahraini dinar',
            'BIF'            => 'BIF - Burundian franc',
            'BMD'            => 'BMD - Bermudian dollar',
            'BND'            => 'BND - Brunei dollar',
            'BOB'            => 'BOB - Boliviano',
            'BRL'            => 'BRL - Brazilian real',
            'BSD'            => 'BSD - Bahamian dollar',
            'BTN'            => 'BTN - Bhutanese ngultrum',
            'BWP'            => 'BWP - Botswana pula',
            'BYN'            => 'BYN - Belarusian ruble',
            'BZD'            => 'BZD - Belize dollar',
            'CAD'            => 'CAD - Canadian dollar',
            'CDF'            => 'CDF - Congolese franc',
            'CHF'            => 'CHF - Swiss franc',
            'CLP'            => 'CLP - Chilean peso',
            'CNY'            => 'CNY - Renminbi (Chinese) yuan',
            'COP'            => 'COP - Colombian peso',
            'COU'            => 'COU - Unidad de Valor Real (UVR)',
            'CRC'            => 'CRC - Costa Rican colon',
            'CUC'            => 'CUC - Cuban convertible peso',
            'CUP'            => 'CUP - Cuban peso',
            'CVE'            => 'CVE - Cape Verde escudo',
            'CZK'            => 'CZK - Czech koruna',
            'DJF'            => 'DJF - Djiboutian franc',
            'DKK'            => 'DKK - Danish krone',
            'DOP'            => 'DOP - Dominican peso',
            'DZD'            => 'DZD - Algerian dinar',
            'EGP'            => 'EGP - Egyptian pound',
            'ERN'            => 'ERN - Eritrean nakfa',
            'ETB'            => 'ETB - Ethiopian birr',
            'EUR'            => 'EUR - Euro',
            'FJD'            => 'FJD - Fiji dollar',
            'FKP'            => 'FKP - Falkland Islands pound',
            'GBP'            => 'GBP - Pound sterling',
            'GEL'            => 'GEL - Georgian lari',
            'GHS'            => 'GHS - Ghanaian cedi',
            'GIP'            => 'GIP - Gibraltar pound',
            'GMD'            => 'GMD - Gambian dalasi',
            'GNF'            => 'GNF - Guinean franc',
            'GTQ'            => 'GTQ - Guatemalan quetzal',
            'GYD'            => 'GYD - Guyanese dollar',
            'HKD'            => 'HKD - Hong Kong dollar',
            'HNL'            => 'HNL - Honduran lempira',
            'HRK'            => 'HRK - Croatian kuna',
            'HTG'            => 'HTG - Haitian gourde',
            'HUF'            => 'HUF - Hungarian forint',
            'IDR'            => 'IDR - Indonesian rupiah',
            'ILS'            => 'ILS - Israeli new shekel',
            'INR'            => 'INR - Indian rupee',
            'IQD'            => 'IQD - Iraqi dinar',
            'IRR'            => 'IRR - Iranian rial',
            'ISK'            => 'ISK - Icelandic krÛna',
            'JMD'            => 'JMD - Jamaican dollar',
            'JOD'            => 'JOD - Jordanian dinar',
            'JPY'            => 'JPY - Japanese yen',
            'KES'            => 'KES - Kenyan shilling',
            'KGS'            => 'KGS - Kyrgyzstani som',
            'KHR'            => 'KHR - Cambodian riel',
            'KMF'            => 'KMF - Comoro franc',
            'KPW'            => 'KPW - North Korean won',
            'KRW'            => 'KRW - South Korean won',
            'KWD'            => 'KWD - Kuwaiti dinar',
            'KYD'            => 'KYD - Cayman Islands dollar',
            'KZT'            => 'KZT - Kazakhstani tenge',
            'LAK'            => 'LAK - Lao kip',
            'LBP'            => 'LBP - Lebanese pound',
            'LKR'            => 'LKR - Sri Lankan rupee',
            'LRD'            => 'LRD - Liberian dollar',
            'LSL'            => 'LSL - Lesotho loti',
            'LYD'            => 'LYD - Libyan dinar',
            'MAD'            => 'MAD - Moroccan dirham',
            'MDL'            => 'MDL - Moldovan leu',
            'MGA'            => 'MGA - Malagasy ariary',
            'MKD'            => 'MKD - Macedonian denar',
            'MMK'            => 'MMK - Myanmar kyat',
            'MNT'            => 'MNT - Mongolian t?gr?g',
            'MOP'            => 'MOP - Macanese pataca',
            'MRU'            => 'MRU - Mauritanian ouguiya',
            'MUR'            => 'MUR - Mauritian rupee',
            'MVR'            => 'MVR - Maldivian rufiyaa',
            'MWK'            => 'MWK - Malawian kwacha',
            'MXN'            => 'MXN - Mexican peso',
            'MXV'            => 'MXV - Mexican Unidad de Inversion (UDI)',
            'MYR'            => 'MYR - Malaysian ringgit',
            'MZN'            => 'MZN - Mozambican metical',
            'NAD'            => 'NAD - Namibian dollar',
            'NGN'            => 'NGN - Nigerian naira',
            'NIO'            => 'NIO - Nicaraguan cÛrdoba',
            'NOK'            => 'NOK - Norwegian krone',
            'NPR'            => 'NPR - Nepalese rupee',
            'NZD'            => 'NZD - New Zealand dollar',
            'OMR'            => 'OMR - Omani rial',
            'PAB'            => 'PAB - Panamanian balboa',
            'PEN'            => 'PEN - Peruvian sol',
            'PGK'            => 'PGK - Papua New Guinean kina',
            'PHP'            => 'PHP - Philippine peso',
            'PKR'            => 'PKR - Pakistani rupee',
            'PLN'            => 'PLN - Polish z?oty',
            'PYG'            => 'PYG - Paraguayan guaranÌ',
            'QAR'            => 'QAR - Qatari riyal',
            'RON'            => 'RON - Romanian leu',
            'RSD'            => 'RSD - Serbian dinar',
            'RUB'            => 'RUB - Russian ruble',
            'RWF'            => 'RWF - Rwandan franc',
            'SAR'            => 'SAR - Saudi riyal',
            'SBD'            => 'SBD - Solomon Islands dollar',
            'SCR'            => 'SCR - Seychelles rupee',
            'SDG'            => 'SDG - Sudanese pound',
            'SEK'            => 'SEK - Swedish krona/kronor',
            'SGD'            => 'SGD - Singapore dollar',
            'SHP'            => 'SHP - Saint Helena pound',
            'SLL'            => 'SLL - Sierra Leonean leone',
            'SOS'            => 'SOS - Somali shilling',
            'SRD'            => 'SRD - Surinamese dollar',
            'SSP'            => 'SSP - South Sudanese pound',
            'STN'            => 'STN - S?o TomÈ and PrÌncipe dobra',
            'SVC'            => 'SVC - Salvadoran colÛn',
            'SYP'            => 'SYP - Syrian pound',
            'SZL'            => 'SZL - Swazi lilangeni',
            'THB'            => 'THB - Thai baht',
            'TJS'            => 'TJS - Tajikistani somoni',
            'TMT'            => 'TMT - Turkmenistan manat',
            'TND'            => 'TND - Tunisian dinar',
            'TOP'            => 'TOP - Tongan pa?anga',
            'TRY'            => 'TRY - Turkish lira',
            'TTD'            => 'TTD - Trinidad and Tobago dollar',
            'TWD'            => 'TWD - New Taiwan dollar',
            'TZS'            => 'TZS - Tanzanian shilling',
            'UAH'            => 'UAH - Ukrainian hryvnia',
            'UGX'            => 'UGX - Ugandan shilling',
            'USD'            => 'USD - United States dollar',
            'UYI'            => 'UYI - Uruguay Peso en Unidades Indexadas',
            'UYU'            => 'UYU - Uruguayan peso',
            'UYW'            => 'UYW - Unidad previsional',
            'UZS'            => 'UZS - Uzbekistan som',
            'VES'            => 'VES - Venezuelan bolÌvar soberano',
            'VND'            => 'VND - Vietnamese ??ng',
            'VUV'            => 'VUV - Vanuatu vatu',
            'WST'            => 'WST - Samoan tala',
            'YER'            => 'YER - Yemeni rial',
            'ZAR'            => 'ZAR - South African rand',
            'ZMW'            => 'ZMW - Zambian kwacha',
            'ZWL'            => 'ZWL - Zimbabwean dollar'];

        $shopCurrencies = Shop::DB()->executeQuery('SELECT * FROM twaehrung', 2);

        $result = [];

        foreach ($shopCurrencies as $sC) {
            if (array_key_exists($sC->cISO, $currencies)) {
                $result[$sC->cISO] = $currencies[$sC->cISO];
            }
        }

        return $result;
    }

    public function loadRequest(&$options = [])
    {
        $oKunde = !$this->getBestellung()->oKunde && $this->PaymentMethod()->duringCheckout ? $_SESSION['Kunde'] : $this->getBestellung()->oKunde;
        if ($this->getBestellung()) {
            if ($oKunde->nRegistriert
                && (
                    $customer = $this->getCustomer(
                    array_key_exists(
                        'mollie_create_customer',
                        $_SESSION['cPost_arr'] ?: []
                    ) && $_SESSION['cPost_arr']['mollie_create_customer'] === 'Y',
                    $oKunde
                )
                )
                && isset($customer)) {
                $options['customerId'] = $customer->id;
            }
            $this->amount      = Amount::factory($this->getBestellung()->fGesamtsummeKundenwaehrung, $this->getBestellung()->Waehrung->cISO, true);
            $this->redirectUrl = $this->PaymentMethod()->duringCheckout ? Shop::getURL() . '/bestellabschluss.php?' . http_build_query(['hash' => $this->getHash()]) : $this->PaymentMethod()->getReturnURL($this->getBestellung());
            $this->metadata    = [
                'kBestellung'   => $this->getBestellung()->kBestellung ?: $this->getBestellung()->cBestellNr,
                'kKunde'        => $this->getBestellung()->kKunde,
                'kKundengruppe' => Session::getInstance()->CustomerGroup()->kKundengruppe,
                'cHash'         => $this->getHash(),
            ];
        }

        $this->locale     = self::getLocale($_SESSION['cISOSprache'], Session::getInstance()->Customer()->cLand);
        $this->webhookUrl = Shop::getURL(true) . '/?' . http_build_query([
                'mollie' => 1,
                'hash'   => $this->getHash(),
                'test'   => $this->API()->isTest() ?: null,
            ]);

        $pm         = $this->PaymentMethod();
        $isPayAgain = strpos($_SERVER['PHP_SELF'], 'bestellab_again') !== false;
        if ($pm::METHOD !== '' && (self::Plugin()->oPluginEinstellungAssoc_arr['resetMethod'] !== 'Y' || !$isPayAgain)) {
            $this->method = $pm::METHOD;
        }
    }

    /**
     * @todo: Kunde wieder löschbar machen ?!
     * @param mixed      $createOrUpdate
     * @param null|mixed $oKunde
     * @return null|\Mollie\Api\Resources\Customer
     */
    public function getCustomer($createOrUpdate = false, $oKunde = null)
    {
        if (!$oKunde) {
            $oKunde = $this->getBestellung()->oKunde;
        }

        if (!$this->customer) {
            $customerModel = Customer::fromID($oKunde->kKunde, 'kKunde');
            if ($customerModel->customerId) {
                try {
                    $this->customer = $this->API()->Client()->customers->get($customerModel->customerId);
                } catch (ApiException $e) {
                    $this->Log(sprintf('Fehler beim laden des Mollie Customers %s (kKunde: %d): %s', $customerModel->customerId, $customerModel->kKunde, $e->getMessage()), LOGLEVEL_ERROR);
                }
            }

            if ($createOrUpdate) {
                $customer = [
                    'name'     => utf8_encode(trim($oKunde->cVorname . ' ' . $oKunde->cNachname)),
                    'email'    => utf8_encode($oKunde->cMail),
                    'locale'   => self::getLocale($_SESSION['cISOSprache'], $oKunde->cLand),
                    'metadata' => (object)[
                        'kKunde'        => $oKunde->kKunde,
                        'kKundengruppe' => $oKunde->kKundengruppe,
                        'cKundenNr'     => utf8_encode($oKunde->cKundenNr),
                    ],
                ];

                if ($this->customer) { // UPDATE

                    $this->customer->name     = $customer['name'];
                    $this->customer->email    = $customer['email'];
                    $this->customer->locale   = $customer['locale'];
                    $this->customer->metadata = $customer['metadata'];

                    try {
                        $this->customer->update();
                    } catch (Exception $e) {
                        $this->Log(sprintf("Fehler beim aktualisieren des Mollie Customers %s: %s\n%s", $this->customer->id, $e->getMessage(), print_r($customer, 1)), LOGLEVEL_ERROR);
                    }
                } else { // create

                    try {
                        $this->customer            = $this->API()->Client()->customers->create($customer);
                        $customerModel->kKunde     = $oKunde->kKunde;
                        $customerModel->customerId = $this->customer->id;
                        $customerModel->save();
                        $this->Log(sprintf("Customer '%s' für Kunde %s (%d) bei Mollie angelegt.", $this->customer->id, $this->customer->name, $this->getBestellung()->kKunde));
                    } catch (Exception $e) {
                        $this->Log(sprintf("Fehler beim anlegen eines Mollie Customers: %s\n%s", $e->getMessage(), print_r($customer, 1)), LOGLEVEL_ERROR);
                    }
                }
            }
        }

        return $this->customer;
    }

    /**
     * Storno Order
     */
    public function storno()
    {
        if (in_array((int)$this->getBestellung()->cStatus, [BESTELLUNG_STATUS_OFFEN, BESTELLUNG_STATUS_IN_BEARBEITUNG], true)) {
            $log = [];

            $conf                  = Shop::getSettings([CONF_GLOBAL, CONF_TRUSTEDSHOPS]);
            $nArtikelAnzeigefilter = (int)$conf['global']['artikel_artikelanzeigefilter'];

            foreach ($this->getBestellung()->Positionen as $pos) {
                if ($pos->kArtikel && $pos->Artikel && $pos->Artikel->cLagerBeachten === 'Y') {
                    $log[] = sprintf('Reset stock of "%s" by %d', $pos->Artikel->cArtNr, -1 * $pos->nAnzahl);
                    self::aktualisiereLagerbestand($pos->Artikel, -1 * $pos->nAnzahl, $pos->WarenkorbPosEigenschaftArr, $nArtikelAnzeigefilter);
                }
            }
            $log[] = sprintf("Cancel order '%s'.", $this->getBestellung()->cBestellNr);

            if (Shop::DB()->executeQueryPrepared('UPDATE tbestellung SET cAbgeholt = \'Y\', cStatus = :cStatus WHERE kBestellung = :kBestellung', [':cStatus' => '-1', ':kBestellung' => $this->getBestellung()->kBestellung], 3)) {
                $this->Log(implode('\n', $log));
            }
        }
    }

    protected static function aktualisiereLagerbestand($Artikel, $nAnzahl, $WarenkorbPosEigenschaftArr, $nArtikelAnzeigefilter = 1)
    {
        $artikelBestand = (float)$Artikel->fLagerbestand;

        if (isset($Artikel->cLagerBeachten) && $Artikel->cLagerBeachten === 'Y') {
            if ($Artikel->cLagerVariation === 'Y' && is_array($WarenkorbPosEigenschaftArr) && count($WarenkorbPosEigenschaftArr) > 0
            ) {
                foreach ($WarenkorbPosEigenschaftArr as $eWert) {
                    $EigenschaftWert = new EigenschaftWert($eWert->kEigenschaftWert);
                    if ($EigenschaftWert->fPackeinheit === .0) {
                        $EigenschaftWert->fPackeinheit = 1;
                    }
                    Shop::DB()->query(
                        'UPDATE teigenschaftwert
                        SET fLagerbestand = fLagerbestand - ' . ($nAnzahl * $EigenschaftWert->fPackeinheit) . '
                        WHERE kEigenschaftWert = ' . (int)$eWert->kEigenschaftWert,
                        4
                    );
                }
            } elseif ($Artikel->fPackeinheit > 0) {
                // Stückliste
                if ($Artikel->kStueckliste > 0) {
                    $artikelBestand = self::aktualisiereStuecklistenLagerbestand($Artikel, $nAnzahl);
                } else {
                    Shop::DB()->query(
                        'UPDATE tartikel
                        SET fLagerbestand = IF (fLagerbestand >= ' . ($nAnzahl * $Artikel->fPackeinheit) . ', 
                        (fLagerbestand - ' . ($nAnzahl * $Artikel->fPackeinheit) . '), fLagerbestand)
                        WHERE kArtikel = ' . (int)$Artikel->kArtikel,
                        4
                    );
                    $tmpArtikel = Shop::DB()->select('tartikel', 'kArtikel', (int)$Artikel->kArtikel, null, null, null, null, false, 'fLagerbestand');
                    if ($tmpArtikel !== null) {
                        $artikelBestand = (float)$tmpArtikel->fLagerbestand;
                    }
                    // Stücklisten Komponente
                    if (ArtikelHelper::isStuecklisteKomponente($Artikel->kArtikel)) {
                        self::aktualisiereKomponenteLagerbestand($Artikel->kArtikel, $artikelBestand, isset($Artikel->cLagerKleinerNull) && $Artikel->cLagerKleinerNull === 'Y');
                    }
                }
                // Aktualisiere Merkmale in tartikelmerkmal vom Vaterartikel
                if ($Artikel->kVaterArtikel > 0) {
                    Artikel::beachteVarikombiMerkmalLagerbestand($Artikel->kVaterArtikel, $nArtikelAnzeigefilter);
                }
            }
        }

        return $artikelBestand;
    }

    protected static function aktualisiereStuecklistenLagerbestand($oStueckListeArtikel, $nAnzahl)
    {
        $nAnzahl             = (float)$nAnzahl;
        $kStueckListe        = (int)$oStueckListeArtikel->kStueckliste;
        $bestandAlt          = (float)$oStueckListeArtikel->fLagerbestand;
        $bestandNeu          = $bestandAlt;
        $bestandUeberverkauf = $bestandAlt;

        if ($nAnzahl > 0) {
            // Gibt es lagerrelevante Komponenten in der Stückliste?
            $oKomponente_arr = Shop::DB()->query(
                "SELECT tstueckliste.kArtikel, tstueckliste.fAnzahl
                FROM tstueckliste
                JOIN tartikel
                  ON tartikel.kArtikel = tstueckliste.kArtikel
                WHERE tstueckliste.kStueckliste = $kStueckListe
                    AND tartikel.cLagerBeachten = 'Y'",
                2
            );

            if (is_array($oKomponente_arr) && count($oKomponente_arr) > 0) {
                // wenn ja, dann wird für diese auch der Bestand aktualisiert
                $options = Artikel::getDefaultOptions();

                $options->nKeineSichtbarkeitBeachten = 1;

                foreach ($oKomponente_arr as $oKomponente) {
                    $tmpArtikel = new Artikel();
                    $tmpArtikel->fuelleArtikel($oKomponente->kArtikel, $options);

                    $komponenteBestand = floor(self::aktualisiereLagerbestand($tmpArtikel, $nAnzahl * $oKomponente->fAnzahl, null) / $oKomponente->fAnzahl);

                    if ($komponenteBestand < $bestandNeu && $tmpArtikel->cLagerKleinerNull !== 'Y') {
                        // Neuer Bestand ist der Kleinste Komponententbestand aller Artikel ohne Überverkauf
                        $bestandNeu = $komponenteBestand;
                    } elseif ($komponenteBestand < $bestandUeberverkauf) {
                        // Für Komponenten mit Überverkauf wird der kleinste Bestand ermittelt.
                        $bestandUeberverkauf = $komponenteBestand;
                    }
                }
            }

            // Ist der alte gleich dem neuen Bestand?
            if ($bestandAlt === $bestandNeu) {
                // Es sind keine lagerrelevanten Komponenten vorhanden, die den Bestand der Stückliste herabsetzen.
                if ($bestandUeberverkauf === $bestandNeu) {
                    // Es gibt auch keine Komponenten mit Überverkäufen, die den Bestand verringern, deshalb wird
                    // der Bestand des Stücklistenartikels anhand des Verkaufs verringert
                    $bestandNeu = $bestandNeu - $nAnzahl * $oStueckListeArtikel->fPackeinheit;
                } else {
                    // Da keine lagerrelevanten Komponenten vorhanden sind, wird der kleinste Bestand der
                    // Komponentent mit Überverkauf verwendet.
                    $bestandNeu = $bestandUeberverkauf;
                }

                Shop::DB()->update('tartikel', 'kArtikel', (int)$oStueckListeArtikel->kArtikel, (object)[
                    'fLagerbestand' => $bestandNeu,
                ]);
            }
            // Kein Lagerbestands-Update für die Stückliste notwendig! Dies erfolgte bereits über die Komponentenabfrage und
            // die dortige Lagerbestandsaktualisierung!
        }

        return $bestandNeu;
    }

    protected static function aktualisiereKomponenteLagerbestand($kKomponenteArtikel, $fLagerbestand, $bLagerKleinerNull)
    {
        $kKomponenteArtikel = (int)$kKomponenteArtikel;
        $fLagerbestand      = (float)$fLagerbestand;

        $oStueckliste_arr = Shop::DB()->query(
            "SELECT tstueckliste.kStueckliste, tstueckliste.fAnzahl,
                tartikel.kArtikel, tartikel.fLagerbestand, tartikel.cLagerKleinerNull
            FROM tstueckliste
            JOIN tartikel
                ON tartikel.kStueckliste = tstueckliste.kStueckliste
            WHERE tstueckliste.kArtikel = $kKomponenteArtikel
                AND tartikel.cLagerBeachten = 'Y'",
            2
        );

        if (is_array($oStueckliste_arr) && count($oStueckliste_arr) > 0) {
            foreach ($oStueckliste_arr as $oStueckliste) {
                // Ist der aktuelle Bestand der Stückliste größer als dies mit dem Bestand der Komponente möglich wäre?
                $maxAnzahl = floor($fLagerbestand / $oStueckliste->fAnzahl);
                if ($maxAnzahl < (float)$oStueckliste->fLagerbestand && (!$bLagerKleinerNull || $oStueckliste->cLagerKleinerNull === 'Y')) {
                    // wenn ja, dann den Bestand der Stückliste entsprechend verringern, aber nur wenn die Komponente nicht
                    // überberkaufbar ist oder die gesamte Stückliste Überverkäufe zulässt
                    Shop::DB()->update('tartikel', 'kArtikel', (int)$oStueckliste->kArtikel, (object)[
                        'fLagerbestand' => $maxAnzahl,
                    ]);
                }
            }
        }
    }

    /**
     * @return null|array|bool|int|object
     */
    public function getLogs()
    {
        return Shop::DB()->executeQueryPrepared('SELECT * FROM tzahlungslog WHERE cLogData LIKE :kBestellung OR cLogData LIKE :cBestellNr OR cLogData LIKE :MollieID ORDER BY dDatum DESC, cLog DESC', [
            ':kBestellung' => '%#' . ($this->getBestellung()->kBestellung ?: '##') . '%',
            ':cBestellNr'  => '%§' . ($this->getBestellung()->cBestellNr ?: '§§') . '%',
            ':MollieID'    => '%$' . ($this->getMollie()->id ?: '$$') . '%',
        ], 2);
    }

    /**
     * @return bool
     */
    public function remindable()
    {
        return (int)$this->getBestellung()->cStatus !== BESTELLUNG_STATUS_STORNO && !in_array($this->getModel()->cStatus, [PaymentStatus::STATUS_PAID, PaymentStatus::STATUS_AUTHORIZED, PaymentStatus::STATUS_PENDING, OrderStatus::STATUS_COMPLETED, OrderStatus::STATUS_SHIPPING], true);
    }

    /**
     * @return string
     */
    public function LogData()
    {
        $data = '';
        if ($this->getBestellung()->kBestellung) {
            $data .= '#' . $this->getBestellung()->kBestellung;
        }
        if ($this->getMollie()) {
            $data .= '$' . $this->getMollie()->id;
        }

        return $data;
    }

    abstract public function cancelOrRefund($force = false);

    /**
     * @param array $paymentOptions
     * @return Order|Payment
     */
    abstract public function create(array $paymentOptions = []);

    /**
     * @return string
     */
    public function getRepayURL()
    {
        return Shop::getURL(true) . '/?m_pay=' . md5($this->getModel()->kID . '-' . $this->getBestellung()->kBestellung);
    }

    public function getDescription()
    {
        $descTemplate = trim(Helper::getSetting('paymentDescTpl')) ?: 'Order {orderNumber}';
        $oKunde       = $this->getBestellung()->oKunde ?: $_SESSION['Kunde'];

        return str_replace([
            '{orderNumber}',
            '{storeName}',
            '{customer.firstname}',
            '{customer.lastname}',
            '{customer.company}',
        ], [
            $this->getBestellung()->cBestellNr,
            Shop::getSettings([CONF_GLOBAL])['global']['global_shopname'],
            $oKunde->cVorname,
            $oKunde->cNachname,
            $oKunde->cFirma

        ], $descTemplate);
    }

    abstract protected function updateOrderNumber();

    /**
     * @param Bestellung $oBestellung
     * @return $this
     */
    protected function setBestellung(Bestellung $oBestellung)
    {
        $this->oBestellung = $oBestellung;

        return $this;
    }

    /**
     * @param \Mollie\Api\Resources\Payment|Order $model
     * @return self
     */
    abstract protected function setMollie($model);
}
