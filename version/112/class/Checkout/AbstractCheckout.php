<?php


namespace ws_mollie\Checkout;


use Bestellung;
use Exception;
use Mollie\Api\Resources\Order;
use PaymentMethod;
use RuntimeException;
use Shop;
use ws_mollie\API;
use ws_mollie\Model\Payment;
use ws_mollie\Traits\Plugin;
use ws_mollie\Traits\RequestData;

abstract class AbstractCheckout
{

    use Plugin;

    use RequestData;

    private $hash;

    /**
     * @var API
     */
    private $api;

    private $paymentMethod;

    /**
     * @var Bestellung
     */
    private $oBestellung;

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
     * @param $model
     * @return OrderCheckout|PaymentCheckout
     * @throws RuntimeException
     */
    public static function fromModel($model)
    {
        if (!$model) {
            throw new RuntimeException(sprintf('Error loading Order for Model: %s', print_r($model, 1)));
        }

        $oBestellung = new Bestellung($model->kBestellung, true);
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
     * @todo
     */
    public static function sendReminders()
    {
        // TODO
    }

    abstract public function cancelOrRefund();

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
                if ($this->completlyPaid()) {
                    $this->PaymentMethod()->setOrderStatusToPaid($this->getBestellung());
                    static::makeFetchable($this->getBestellung(), $this->getModel());
                    $this->PaymentMethod()->deletePaymentHash($this->getHash());

                    $this->PaymentMethod()->doLog(sprintf("Checkout::handleNotification: Bestellung '%s' als bezahlt markiert: %.2f %s", $this->getBestellung()->cBestellNr, (float)$incoming->fBetrag, $incoming->cISO), LOGLEVEL_NOTICE);

                    $oZahlungsart = Shop::DB()->selectSingleRow('tzahlungsart', 'cModulId', $this->PaymentMethod()->moduleID);
                    if ($oZahlungsart && (int)$oZahlungsart->nMailSenden === 1) {
                        $this->PaymentMethod()->sendConfirmationMail($this->getBestellung());
                    }
                } else {
                    $this->PaymentMethod()->doLog(sprintf("Checkout::handleNotification: Bestellung '%s': nicht komplett bezahlt: %.2f %s", $this->getBestellung()->cBestellNr, (float)$incoming->fBetrag, $incoming->cISO), LOGLEVEL_ERROR);
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
     * @return PaymentMethod
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
        }

        $this->getModel()->kBestellung = $this->getBestellung()->kBestellung;
        $this->getModel()->cOrderNumber = $this->getBestellung()->cBestellNr;
        $this->getModel()->cHash = $this->getHash();
        $this->getModel()->bSynced = $this->getModel()->bSynced !== null ? $this->getModel()->bSynced : false;
        return $this;
    }

    /**
     * @return bool
     */
    public function saveModel()
    {
        return $this->getModel()->save();
    }

    /**
     * @param false $force
     * @return Order|\Mollie\Api\Resources\Payment
     */
    abstract public function getMollie($force = false);

    /**
     * @return \stdClass
     */
    abstract public function getIncomingPayment();

    /**
     * @return bool
     */
    public function completlyPaid()
    {

        if ($row = Shop::DB()->executeQueryPrepared("SELECT SUM(fBetrag) as fBetragSumme FROM tzahlungseingang WHERE kBestellung = :kBestellung", [
            ':kBestellung' => $this->getBestellung()->kBestellung
        ], 1)) {
            return $row->fBetragSumme >= ($this->getBestellung()->fGesamtsumme * (float)$this->getBestellung()->fWaehrungsFaktor);
        }
        return false;

    }

    /**
     * @param Bestellung $oBestellung
     * @param Payment $model
     * @return bool
     */
    public static function makeFetchable(Bestellung $oBestellung, Payment $model)
    {
        if ($oBestellung->cAbgeholt === 'Y' && !$model->bSynced) {
            Shop::DB()->update('tbestellung', 'kBestellung', (int)$oBestellung->kBestellung, (object)['cAbgeholt' => 'N']);
            $model->bSynced = true;
            try {
                return $model->save();
            } catch (Exception $e) {
                \Jtllog::writeLog(sprintf("Fehler beim speichern des Models: %s / Bestellung: %s", $model->kID, $oBestellung->cBestellNr));
            }
        }
        return false;
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

}