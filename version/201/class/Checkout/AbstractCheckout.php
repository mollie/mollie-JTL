<?php


namespace ws_mollie\Checkout;


use Bestellung;
use Exception;
use Jtllog;
use JTLMollie;
use Kunde;
use Mollie\Api\Exceptions\ApiException;
use Mollie\Api\Resources\Order;
use Mollie\Api\Types\OrderStatus;
use Mollie\Api\Types\PaymentStatus;
use PaymentMethod;
use RuntimeException;
use Session;
use Shop;
use stdClass;
use ws_mollie\API;
use ws_mollie\Helper;
use ws_mollie\Model\Customer;
use ws_mollie\Model\Payment;
use ws_mollie\Traits\Plugin;
use ws_mollie\Traits\RequestData;
use ZahlungsLog;

abstract class AbstractCheckout
{

    use RequestData, Plugin;

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
     * @var \Mollie\Api\Resources\Customer|null
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
     * @param $oBestellung
     * @param null $api
     */
    public function __construct(Bestellung $oBestellung, $api = null)
    {
        $this->api = $api;
        $this->oBestellung = $oBestellung;
    }

    /**
     * @param int $kBestellung
     * @param bool $checkZA
     * @return bool
     */
    public static function isMollie($kBestellung, $checkZA = false)
    {
        if ($checkZA) {
            $res = Shop::DB()->executeQueryPrepared('SELECT * FROM tzahlungsart WHERE cModulId LIKE :cModulId AND kZahlungsart = :kZahlungsart', [
                ':kZahlungsart' => $kBestellung,
                ':cModulId' => 'kPlugin_' . self::Plugin()->kPlugin . '_%'
            ], 1);
            return $res ? true : false;
        }

        return ($res = Shop::DB()->executeQueryPrepared('SELECT kId FROM xplugin_ws_mollie_payments WHERE kBestellung = :kBestellung;', [
                ':kBestellung' => $kBestellung,
            ], 1)) && $res->kId;
    }

    /**
     * @param $kBestellung
     * @return OrderCheckout|PaymentCheckout
     * * @throws RuntimeException
     */
    public static function fromBestellung($kBestellung)
    {
        if ($model = Payment::fromID($kBestellung, 'kBestellung')) {
            return self::fromModel($model);
        }
        throw new RuntimeException(sprintf('Error loading Order for Bestellung: %s', $kBestellung));
    }

    /**
     * @param $model
     * @return OrderCheckout|PaymentCheckout
     * @throws RuntimeException
     */
    public static function fromModel($model, $bFill = true)
    {
        if (!$model) {
            throw new RuntimeException(sprintf('Error loading Order for Model: %s', print_r($model, 1)));
        }

        $oBestellung = new Bestellung($model->kBestellung, $bFill);
        if (!$oBestellung->kBestellung) {
            throw new RuntimeException(sprintf('Error loading Bestellung: %s', $model->kBestellung));
        }

        if (strpos($model->kID, 'tr_') !== false) {
            $self = new PaymentCheckout($oBestellung);
        } else {
            $self = new OrderCheckout($oBestellung);
        }

        $self->setModel($model);
        return $self;
    }

    public static function sendReminders()
    {
        $reminder = (int)self::Plugin()->oPluginEinstellungAssoc_arr['reminder'];

        if (!$reminder) {
            return;
        }

        $sql = "SELECT p.kId FROM xplugin_ws_mollie_payments p JOIN tbestellung b ON b.kBestellung = p.kBestellung "
            . "WHERE (p.dReminder IS NULL OR p.dReminder = '0000-00-00 00:00:00') "
            . "AND p.dCreatedAt < NOW() - INTERVAL :d HOUR AND p.dCreatedAt > NOW() - INTERVAL 7 DAY "
            . "AND p.cStatus IN ('created','open', 'expired', 'failed', 'canceled') AND NOT b.cStatus = '-1'";

        $remindables = Shop::DB()->executeQueryPrepared($sql, [
            ':d' => $reminder
        ], 2);
        foreach ($remindables as $remindable) {
            try {
                self::sendReminder($remindable->kId);
            } catch (Exception $e) {
                Jtllog::writeLog("AbstractCheckout::sendReminders: " . $e->getMessage());
            }
        }
    }

    public static function sendReminder($kID)
    {

        $checkout = self::fromID($kID);
        $return = true;
        try {
            $repayURL = Shop::getURL() . '/?m_pay=' . md5($checkout->getModel()->kID . '-' . $checkout->getBestellung()->kBestellung);

            $data = new stdClass();
            $data->tkunde = new Kunde($checkout->getBestellung()->oKunde->kKunde);
            if ($data->tkunde->kKunde) {

                $data->Bestellung = $checkout->getBestellung();
                $data->PayURL = $repayURL;
                $data->Amount = gibPreisStringLocalized($checkout->getModel()->fAmount, $checkout->getBestellung()->Waehrung); //Preise::getLocalizedPriceString($order->getAmount(), Currency::fromISO($order->getCurrency()), false);

                require_once PFAD_ROOT . PFAD_INCLUDES . 'mailTools.php';

                $mail = new stdClass();
                $mail->toEmail = $data->tkunde->cMail;
                $mail->toName = trim((isset($data->tKunde->cVorname)
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
                    $checkout->Log(sprintf("Zahlungserinnerung f�r %s verschickt.", $checkout->getBestellung()->cBestellNr));
                }
            } else {
                $checkout->Log("Kunde '{$checkout->getBestellung()->oKunde->kKunde}' nicht gefunden.", LOGLEVEL_ERROR);
            }
        } catch (Exception $e) {
            $checkout->Log(sprintf("AbstractCheckout::sendReminder: Zahlungserinnerung f�r %s fehlgeschlagen: %s", $checkout->getBestellung()->cBestellNr, $e->getMessage()));
            $return = false;
        }
        $checkout->getModel()->dReminder = date('Y-m-d H:i:s');
        $checkout->getModel()->save();
        return $return;
    }

    /**
     * @param $id
     * @return OrderCheckout|PaymentCheckout
     * @throws RuntimeException
     */
    public static function fromID($id)
    {
        if ($model = Payment::fromID($id)) {
            return static::fromModel($model);
        }
        throw new RuntimeException(sprintf('Error loading Order: %s', $id));
    }

    /**
     * @param AbstractCheckout $checkout
     * @return \Mollie\Api\Resources\BaseResource|\Mollie\Api\Resources\Refund
     * @throws ApiException
     */
    public static function refund(AbstractCheckout $checkout)
    {
        if ($checkout->getMollie()->resource === 'order') {
            /** @var Order $order */
            $order = $checkout->getMollie();
            if (in_array($order->status, [OrderStatus::STATUS_CANCELED, OrderStatus::STATUS_EXPIRED, OrderStatus::STATUS_CREATED], true)) {
                throw new RuntimeException('Bestellung kann derzeit nicht zur�ckerstattet werden.');
            }
            $refund = $order->refundAll();
            $checkout->Log(sprintf('Bestellung wurde manuell zur�ckerstattet: %s', $refund->id));
            return $refund;
        }
        if ($checkout->getMollie()->resource === 'payment') {
            /** @var \Mollie\Api\Resources\Payment $payment */
            $payment = $checkout->getMollie();
            if (in_array($payment->status, [PaymentStatus::STATUS_CANCELED, PaymentStatus::STATUS_EXPIRED, PaymentStatus::STATUS_OPEN], true)) {
                throw new RuntimeException('Zahlung kann derzeit nicht zur�ckerstattet werden.');
            }
            $refund = $checkout->API()->Client()->payments->refund($checkout->getMollie(), ['amount' => $checkout->getMollie()->amount]);
            $checkout->Log(sprintf('Zahlung wurde manuell zur�ckerstattet: %s', $refund->id));
            return $refund;
        }
        throw new RuntimeException(sprintf('Unbekannte Resource: %s', $checkout->getMollie()->resource));
    }

    /**
     * @param false $force
     * @return Order|\Mollie\Api\Resources\Payment|null
     */
    abstract public function getMollie($force = false);

    public function Log($msg, $level = LOGLEVEL_NOTICE)
    {
        $data = '';
        if ($this->getBestellung()) {
            $data .= '#' . $this->getBestellung()->kBestellung;
        }
        if ($this->getMollie()) {
            $data .= '$' . $this->getMollie()->id;
        }
        ZahlungsLog::add($this->PaymentMethod()->moduleID, "[" . microtime(true) . " - " . $_SERVER['PHP_SELF'] . "] " . $msg, $data, $level);
        return $this;
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
     * @return JTLMollie
     */
    public function PaymentMethod()
    {
        if (!$this->paymentMethod) {
            if ($this->getBestellung()->Zahlungsart && strpos($this->getBestellung()->Zahlungsart->cModulId, "kPlugin_{$this::Plugin()->kPlugin}_") !== false) {
                $this->paymentMethod = PaymentMethod::create($this->getBestellung()->Zahlungsart->cModulId);
            } else {
                $this->paymentMethod = PaymentMethod::create("kPlugin_{$this::Plugin()->kPlugin}_mollie");
            }
        }
        /** @noinspection PhpIncompatibleReturnTypeInspection */
        return $this->paymentMethod;
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
     * @return Order|\Mollie\Api\Resources\Payment
     * @throws ApiException
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

    /**
     * @return array|bool|int|object|null
     */
    public function getLogs()
    {
        return Shop::DB()->executeQueryPrepared("SELECT * FROM tzahlungslog WHERE cLogData LIKE :kBestellung OR cLogData LIKE :cBestellNr OR cLogData LIKE :MollieID ORDER BY dDatum DESC, cLog DESC", [
            ':kBestellung' => '%#' . ($this->getBestellung()->kBestellung ?: '##') . '%',
            ':cBestellNr' => '%�' . ($this->getBestellung()->cBestellNr ?: '��') . '%',
            ':MollieID' => '%$' . ($this->getMollie()->id ?: '$$') . '%',
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

    /**
     * @return \Mollie\Api\Resources\Customer|null
     * @todo: Kunde wieder l�schbar machen ?!
     */
    public function getCustomer($createOrUpdate = false)
    {
        if (!$this->customer) {
            $customerModel = Customer::fromID($this->getBestellung()->oKunde->kKunde, 'kKunde');
            if ($customerModel->customerId) {
                try {
                    $this->customer = $this->API()->Client()->customers->get($customerModel->customerId);
                } catch (ApiException $e) {
                    $this->Log(sprintf("Fehler beim laden des Mollie Customers %s (kKunde: %d): %s", $customerModel->customerId, $customerModel->kKunde, $e->getMessage()), LOGLEVEL_ERROR);
                }
            }

            if ($createOrUpdate) {
                $oKunde = $this->getBestellung()->oKunde;

                $customer = [
                    'name' => trim($oKunde->cVorname . ' ' . $oKunde->cNachname),
                    'email' => $oKunde->cMail,
                    'locale' => self::getLocale($_SESSION['cISOSprache'], $oKunde->cLand),
                    'metadata' => (object)[
                        'kKunde' => $oKunde->kKunde,
                        'kKundengruppe' => $oKunde->kKundengruppe,
                        'cKundenNr' => $oKunde->cKundenNr,
                    ],
                ];

                if ($this->customer) { // UPDATE

                    $this->customer->name = $customer['name'];
                    $this->customer->email = $customer['email'];
                    $this->customer->locale = $customer['locale'];
                    $this->customer->metadata = $customer['metadata'];

                    try {
                        $this->customer->update();
                    } catch (Exception $e) {
                        $this->Log(sprintf("Fehler beim aktualisieren des Mollie Customers %s: %s\n%s", $this->customer->id, $e->getMessage(), print_r($customer, 1)), LOGLEVEL_ERROR);
                    }


                } else { // create

                    try {
                        $this->customer = $this->API()->Client()->customers->create($customer);
                        $customerModel->kKunde = $oKunde->kKunde;
                        $customerModel->customerId = $this->customer->id;
                        $customerModel->save();
                        $this->Log(sprintf("Customer '%s' f�r Kunde %s (%d) bei Mollie angelegt.", $this->customer->id, $this->customer->name, $this->getBestellung()->kKunde));
                    } catch (Exception $e) {
                        $this->Log(sprintf("Fehler beim anlegen eines Mollie Customers: %s\n%s", $e->getMessage(), print_r($customer, 1)), LOGLEVEL_ERROR);
                    }
                }
            }
        }
        return $this->customer;
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

    abstract public function cancelOrRefund($force = false);

    /**
     * @param array $paymentOptions
     * @return Payment|Order
     */
    abstract public function create(array $paymentOptions = []);

    /**
     * @param null $hash
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
     * @return bool
     */
    public function saveModel()
    {
        return $this->getModel()->save();
    }

    /**
     * @return $this
     */
    public function updateModel()
    {

        if ($this->getMollie()) {
            $this->getModel()->kID = $this->getMollie()->id;
            $this->getModel()->cLocale = $this->getMollie()->locale;
            $this->getModel()->fAmount = (float)$this->getMollie()->amount->value;
            $this->getModel()->cMethod = $this->getMollie()->method;
            $this->getModel()->cCurrency = $this->getMollie()->amount->currency;
            $this->getModel()->cStatus = $this->getMollie()->status;
            if ($this->getMollie()->amountRefunded) {
                $this->getModel()->fAmountRefunded = $this->getMollie()->amountRefunded->value;
            }
            if ($this->getMollie()->amountCaptured) {
                $this->getModel()->fAmountCaptured = $this->getMollie()->amountCaptured->value;
            }
            $this->getModel()->cMode = $this->getMollie()->mode ?: null;
            $this->getModel()->cRedirectURL = $this->getMollie()->redirectUrl;
            $this->getModel()->cWebhookURL = $this->getMollie()->webhookUrl;
            $this->getModel()->cCheckoutURL = $this->getMollie()->getCheckoutUrl();
        }

        $this->getModel()->kBestellung = $this->getBestellung()->kBestellung;
        $this->getModel()->cOrderNumber = $this->getBestellung()->cBestellNr;
        $this->getModel()->cHash = $this->getHash();

        $this->getModel()->bSynced = $this->getModel()->bSynced !== null ? $this->getModel()->bSynced : Helper::getSetting('onlyPaid') !== 'Y';
        return $this;
    }

    /**
     * @return stdClass
     */
    abstract public function getIncomingPayment();

    /**
     * @param Bestellung $oBestellung
     * @param Payment $model
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
            Jtllog::writeLog(sprintf("Fehler beim speichern des Models: %s / Bestellung: %s", $model->kID, $oBestellung->cBestellNr));
        }
        return false;
    }

    /**
     * @return bool
     */
    public function completlyPaid()
    {

        if ($row = Shop::DB()->executeQueryPrepared("SELECT SUM(fBetrag) as fBetragSumme FROM tzahlungseingang WHERE kBestellung = :kBestellung", [
            ':kBestellung' => $this->getBestellung()->kBestellung
        ], 1)) {
            return $row->fBetragSumme >= round($this->getBestellung()->fGesamtsumme * (float)$this->getBestellung()->fWaehrungsFaktor, 2);
        }
        return false;

    }

    /**
     * @return string
     */
    public function getRepayURL()
    {
        return Shop::getURL(true) . '/?m_pay=' . md5($this->getModel()->kID . '-' . $this->getBestellung()->kBestellung);
    }

}