<?php

/**
 * @copyright 2022 WebStollen GmbH
 * @link https://www.webstollen.de
 */

namespace ws_mollie\Checkout;

use Exception;
use Mollie\Api\Exceptions\ApiException;
use Mollie\Api\Resources\Order;
use Mollie\Api\Resources\Payment;
use Mollie\Api\Types\OrderStatus;
use Mollie\Api\Types\PaymentStatus;
use RuntimeException;
use Session;
use Shop;
use ws_mollie\Checkout\Order\Address;
use ws_mollie\Checkout\Order\OrderLine;
use ws_mollie\Checkout\Payment\Amount;
use ws_mollie\Helper;
use ws_mollie\Shipment;

/**
 * Class OrderCheckout
 * @package ws_mollie\Checkout
 *
 * @property string $locale
 * @property Amount $amount
 * @property string $orderNumber
 * @property null|array $metadata
 * @property string $redirectUrl
 * @property string $webhookUrl
 * @property null|string $method
 * @property Address $billingAddress
 * @property null|Address $shippingAddress
 * @property null|string $consumerDateOfBirth
 * @property OrderLine[] $lines
 * @property null|string $expiresAt
 * @property null|array $payment
 */
class OrderCheckout extends AbstractCheckout
{
    /**
     * @var Order
     */
    protected $order;

    /**
     * @var null|Payment
     */
    protected $_payment;

    /**
     * @param OrderCheckout $checkout
     * @throws ApiException
     * @throws \Mollie\Api\Exceptions\IncompatiblePlatform
     * @return string
     */
    public static function capture(self $checkout)
    {
        if ($checkout->getMollie()->status !== OrderStatus::STATUS_AUTHORIZED && $checkout->getMollie()->status !== OrderStatus::STATUS_SHIPPING) {
            throw new RuntimeException('Nur autorisierte Zahlungen können erfasst werden!');
        }
        $shipment = $checkout->API()->Client()->shipments->createFor($checkout->getMollie(), ['lines' => []]);
        $checkout->Log(sprintf('Bestellung wurde manuell erfasst/versandt: %s', $shipment->id));

        return $shipment->id;
    }

    /**
     * @param false $force
     * @return null|Order
     */
    public function getMollie($force = false)
    {
        if ($force || (!$this->order && $this->getModel()->kID)) {
            try {
                $this->order = $this->API()->Client()->orders->get($this->getModel()->kID, ['embed' => 'payments,shipments,refunds']);
            } catch (Exception $e) {
                throw new RuntimeException(sprintf('Mollie-Order \'%s\' konnte nicht geladen werden: %s', $this->getModel()->kID, $e->getMessage()));
            }
        }

        return $this->order;
    }

    /**
     * @param OrderCheckout $checkout
     * @throws ApiException
     * @return Order
     */
    public static function cancel($checkout)
    {
        if (!$checkout->getMollie()->isCancelable) {
            throw new RuntimeException('Bestellung kann nicht abgebrochen werden.');
        }
        $order = $checkout->getMollie()->cancel();
        $checkout->Log('Bestellung wurde manuell abgebrochen.');

        return $order;
    }

    /**
     * @return array
     */
    public function getShipments()
    {
        $shipments        = [];
        $lieferschien_arr = Shop::DB()->executeQueryPrepared('SELECT kLieferschein FROM tlieferschein WHERE kInetBestellung = :kBestellung', [
            ':kBestellung' => (int)$this->getBestellung()->kBestellung
        ], 2);

        foreach ($lieferschien_arr as $lieferschein) {
            $shipments[] = new Shipment($lieferschein->kLieferschein, $this);
        }

        return $shipments;
    }

    /**
     * @param mixed $force
     * @throws ApiException
     * @return string
     */
    public function cancelOrRefund($force = false)
    {
        if (!$this->getMollie()) {
            throw new RuntimeException('Mollie-Order konnte nicht geladen werden: ' . $this->getModel()->kID);
        }
        if ($force || (int)$this->getBestellung()->cStatus === BESTELLUNG_STATUS_STORNO) {
            if ($this->getMollie()->isCancelable) {
                $res    = $this->getMollie()->cancel();
                $result = 'Order cancelled, Status: ' . $res->status;
            } else {
                $res    = $this->getMollie()->refundAll();
                $result = 'Order Refund initiiert, Status: ' . $res->status;
            }
            $this->PaymentMethod()->Log('OrderCheckout::cancelOrRefund: ' . $result, $this->LogData());

            return $result;
        }

        throw new RuntimeException('Bestellung ist derzeit nicht storniert, Status: ' . $this->getBestellung()->cStatus);
    }

    /**
     * @param array $paymentOptions
     * @return Order|Payment
     */
    public function create(array $paymentOptions = [])
    {
        if ($this->getModel()->kID) {
            try {
                $this->order = $this->API()->Client()->orders->get($this->getModel()->kID, ['embed' => 'payments']);
                if (in_array($this->order->status, [OrderStatus::STATUS_COMPLETED, OrderStatus::STATUS_PAID, OrderStatus::STATUS_AUTHORIZED, OrderStatus::STATUS_PENDING], true)) {
                    $this->handleNotification();

                    throw new RuntimeException(self::Plugin()->oPluginSprachvariableAssoc_arr['errAlreadyPaid']);
                }
                if ($this->order->status === OrderStatus::STATUS_CREATED) {
                    if ($this->order->payments()) {
                        /** @var Payment $payment */
                        foreach ($this->order->payments() as $payment) {
                            if ($payment->status === PaymentStatus::STATUS_OPEN) {
                                $this->setPayment($payment);

                                break;
                            }
                        }
                    }
                    if (!$this->getPayment()) {
                        $this->setPayment($this->API()->Client()->orderPayments->createForId($this->getModel()->kID, $paymentOptions));
                    }
                    $this->updateModel()->saveModel();

                    return $this->getMollie(true);
                }
            } catch (RuntimeException $e) {
                throw $e;
            } catch (Exception $e) {
                $this->Log(sprintf("OrderCheckout::create: Letzte Order '%s' konnte nicht geladen werden: %s, versuche neue zu erstellen.", $this->getModel()->kID, $e->getMessage()), LOGLEVEL_ERROR);
            }
        }

        try {
            $req         = $this->loadRequest()->jsonSerialize();
            $this->order = $this->API()->Client()->orders->create($req);
            $this->Log(sprintf("Order für '%s' wurde erfolgreich angelegt: %s", $this->getBestellung()->cBestellNr, $this->order->id));
            $this->updateModel()->saveModel();

            return $this->order;
        } catch (Exception $e) {
            $this->Log(sprintf("OrderCheckout::create: Neue Order '%s' konnte nicht erstellt werden: %s.", $this->getBestellung()->cBestellNr, $e->getMessage()), LOGLEVEL_ERROR);

            throw new RuntimeException(sprintf("Order für '%s' konnte nicht angelegt werden: %s", $this->getBestellung()->cBestellNr, $e->getMessage()));
        }
    }

    /**
     * @param mixed $search
     * @return null|Payment
     */
    public function getPayment($search = false)
    {
        if (!$this->_payment && $search) {
            foreach ($this->getMollie()->payments() as $payment) {
                if (
                    in_array($payment->status, [
                    PaymentStatus::STATUS_AUTHORIZED,
                    PaymentStatus::STATUS_PAID,
                    PaymentStatus::STATUS_PENDING,
                    ], true)
                ) {
                    $this->_payment = $payment;

                    break;
                }
            }
        }

        return $this->_payment;
    }

    /**
     * @param $payment
     * @return $this
     */
    public function setPayment($payment)
    {
        $this->_payment = $payment;

        return $this;
    }

    /**
     * @return $this|OrderCheckout
     */
    public function updateModel()
    {
        parent::updateModel();

        if (!$this->getPayment() && $this->getMollie() && $this->getMollie()->payments()) {
            /** @var Payment $payment */
            foreach ($this->getMollie()->payments() as $payment) {
                if (in_array($payment->status, [PaymentStatus::STATUS_OPEN, PaymentStatus::STATUS_PENDING, PaymentStatus::STATUS_AUTHORIZED, PaymentStatus::STATUS_PAID], true)) {
                    $this->setPayment($payment);

                    break;
                }
            }
        }
        if ($this->getPayment()) {
            $this->getModel()->cTransactionId = $this->getPayment()->id;
        }
        if ($this->getMollie()) {
            $this->getModel()->cCheckoutURL = $this->getMollie()->getCheckoutUrl();
            $this->getModel()->cWebhookURL  = $this->getMollie()->webhookUrl;
            $this->getModel()->cRedirectURL = $this->getMollie()->redirectUrl;
        }

        return $this;
    }

    /**
     * @param array $options
     * @return $this|OrderCheckout
     */
    public function loadRequest($options = [])
    {
        if (
            $this->getBestellung()->oKunde->nRegistriert
            && (
                $customer = $this->getCustomer(
                    array_key_exists('mollie_create_customer', $_SESSION['cPost_arr'] ?: []) && $_SESSION['cPost_arr']['mollie_create_customer'] === 'Y'
                )
            )
            && isset($customer)
        ) {
            $options['customerId'] = $customer->id;
        }

        $this->locale      = self::getLocale(Session::getInstance()->Language()->getIso(), Session::getInstance()->Customer()->cLand);
        $this->amount      = Amount::factory($this->getBestellung()->fGesamtsummeKundenwaehrung, $this->getBestellung()->Waehrung->cISO, true);
        $this->orderNumber = $this->getBestellung()->cBestellNr;
        $this->metadata    = [
            'kBestellung'   => $this->getBestellung()->kBestellung,
            'kKunde'        => $this->getBestellung()->kKunde,
            'kKundengruppe' => Session::getInstance()->CustomerGroup()->kKundengruppe,
            'cHash'         => $this->getHash(),
        ];

        $this->redirectUrl = $this->PaymentMethod()->getReturnURL($this->getBestellung());
        $this->webhookUrl  = Shop::getURL(true) . '/?mollie=1';

        $pm         = $this->PaymentMethod();
        $isPayAgain = strpos($_SERVER['PHP_SELF'], 'bestellab_again') !== false;
        if ($pm::METHOD !== '' && (self::Plugin()->oPluginEinstellungAssoc_arr['resetMethod'] !== 'Y' || !$isPayAgain)) {
            $this->method = $pm::METHOD;
        }

        $this->billingAddress = Address::factory($this->getBestellung()->oRechnungsadresse);
        if ($this->getBestellung()->Lieferadresse !== null) {
            if (!$this->getBestellung()->Lieferadresse->cMail) {
                $this->getBestellung()->Lieferadresse->cMail = $this->getBestellung()->oRechnungsadresse->cMail;
            }
            $this->shippingAddress = Address::factory($this->getBestellung()->Lieferadresse);
        }

        if (
            !empty(Session::getInstance()->Customer()->dGeburtstag)
            && Session::getInstance()->Customer()->dGeburtstag !== '0000-00-00'
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim(Session::getInstance()->Customer()->dGeburtstag))
        ) {
            $this->consumerDateOfBirth = trim(Session::getInstance()->Customer()->dGeburtstag);
        }

        $lines = [];
        foreach ($this->getBestellung()->Positionen as $oPosition) {
            $lines[] = OrderLine::factory($oPosition, $this->getBestellung()->Waehrung);
        }

        if ($this->getBestellung()->GuthabenNutzen && $this->getBestellung()->fGuthaben > 0) {
            $lines[] = OrderLine::getCredit($this->getBestellung());
        }

        if ($comp = OrderLine::getRoundingCompensation($lines, $this->amount, $this->getBestellung()->Waehrung->cISO)) {
            $lines[] = $comp;
        }
        $this->lines = $lines;

        if ($dueDays = $this->PaymentMethod()->getExpiryDays()) {
            $max             = $this->method && strpos($this->method, 'klarna') !== false ? 28 : 100;
            $this->expiresAt = date('Y-m-d', strtotime(sprintf('+%d DAYS', min($dueDays, $max))));
        }

        $this->payment = $options;

        return $this;
    }

    /**
     * @return null|object
     */
    public function getIncomingPayment()
    {
        if (!$this->getMollie()) {
            return null;
        }

        if (Helper::getSetting('wawiPaymentID') === 'ord') {
            $cHinweis = $this->getMollie()->id;
        } else {
            if (Helper::getSetting('wawiPaymentID') === 'tr') {
                $cHinweis = $this->getPayment(true)->id;
            } else {
                $cHinweis = sprintf('%s / %s', $this->getMollie()->id, $this->getPayment(true)->id);
            }
        }

        /** @var Payment $payment */
        foreach ($this->getMollie()->payments() as $payment) {
            if (
                in_array(
                    $payment->status,
                    [PaymentStatus::STATUS_AUTHORIZED, PaymentStatus::STATUS_PAID],
                    true
                )
            ) {
                $this->setPayment($payment);
                $data = (object)[
                    'fBetrag'  => (float)$payment->amount->value,
                    'cISO'     => $payment->amount->currency,
                    'cZahler'  => $payment->details->paypalPayerId ?: $payment->customerId,
                    'cHinweis' => $payment->details->paypalReference ?: $cHinweis,
                ];
                if (isset($payment->details, $payment->details->paypalFee)) {
                    $data->fZahlungsgebuehr = $payment->details->paypalFee->value;
                }

                return $data;
            }
        }

        return null;
    }
}
