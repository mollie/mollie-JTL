<?php


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

class OrderCheckout extends AbstractCheckout
{

    /**
     * @var Order
     */
    protected $order;

    /**
     * @var Payment
     */
    protected $payment;

    /**
     * @return string
     * @throws ApiException
     */
    public function cancelOrRefund()
    {
        if (!$this->getMollie()) {
            throw new RuntimeException('Mollie-Order konnte nicht geladen werden: ' . $this->getModel()->kID);
        }
        if ((int)$this->getBestellung()->cStatus === BESTELLUNG_STATUS_STORNO) {
            if ($this->getMollie()->isCancelable) {
                $res = $this->getMollie()->cancel();
                return 'Order cancelled, Status: ' . $res->status;
            }
            $res = $this->getMollie()->refundAll();
            return "Order Refund initiiert, Status: " . $res->status;
        }
        throw new RuntimeException('Bestellung ist derzeit nicht storniert, Status: ' . $this->getBestellung()->cStatus);
    }

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
                                $this->payment = $payment;
                                break;
                            }
                        }
                    }
                    if (!$this->payment) {
                        $this->payment = $this->API()->Client()->orderPayments->createForId($this->getModel()->kID, $paymentOptions);
                    }
                    $this->updateModel()->saveModel();
                    return $this->getMollie(true);
                }
            } catch (RuntimeException $e) {
                //$this->Log(sprintf("OrderCheckout::create: Letzte Order '%s' konnte nicht geladen werden: %s", $this->getModel()->kID, $e->getMessage()), LOGLEVEL_ERROR);
                throw $e;
            } catch (Exception $e) {
                $this->Log(sprintf("OrderCheckout::create: Letzte Order '%s' konnte nicht geladen werden: %s, versuche neue zu erstellen.", $this->getModel()->kID, $e->getMessage()), LOGLEVEL_ERROR);
            }
        }

        try {
            $req = $this->loadRequest($paymentOptions)->getRequestData();
            $this->order = $this->API()->Client()->orders->create($req);
            $this->Log(sprintf("Order f�r '%s' wurde erfolgreich angelegt: %s", $this->getBestellung()->cBestellNr, $this->order->id));
            $this->updateModel()->saveModel();
        } catch (Exception $e) {
            $this->Log(sprintf("OrderCheckout::create: Neue Order '%s' konnte nicht erstellt werden: %s.", $this->getBestellung()->cBestellNr, $e->getMessage()), LOGLEVEL_ERROR);
            throw new RuntimeException(sprintf("Order f�r '%s' konnte nicht angelegt werden: %s", $this->getBestellung()->cBestellNr, $e->getMessage()));
        }
        return $this->order;
    }

    public function updateModel()
    {
        parent::updateModel();

        if (!$this->payment && $this->getMollie() && $this->getMollie()->payments()) {
            /** @var Payment $payment */
            foreach ($this->getMollie()->payments() as $payment) {
                if (in_array($payment->status, [PaymentStatus::STATUS_OPEN, PaymentStatus::STATUS_PENDING, PaymentStatus::STATUS_AUTHORIZED, PaymentStatus::STATUS_PAID], true)) {
                    $this->payment = $payment;
                    break;
                }
            }
        }
        if ($this->payment) {
            $this->getModel()->cTransactionId = $this->payment->id;

        }
        if ($this->getMollie()) {
            $this->getModel()->cCheckoutURL = $this->getMollie()->getCheckoutUrl();
            $this->getModel()->cWebhookURL = $this->getMollie()->webhookUrl;
            $this->getModel()->cRedirectURL = $this->getMollie()->redirectUrl;
        }

        return $this;
    }

    /**
     * @param array $options
     * @return $this|OrderCheckout
     * @throws ApiException
     * @throws \Mollie\Api\Exceptions\IncompatiblePlatform
     */
    public function loadRequest($options = [])
    {

        if ((int)$this->getBestellung()->oKunde->nRegistriert
            && ($customer = $this->getCustomer(array_key_exists('mollie_create_customer', $_SESSION['cPost_arr'] ?: []) || $_SESSION['cPost_arr']['mollie_create_customer'] !== 'Y'))
            && isset($customer)) {
            $options['customerId'] = $customer->id;
        }

        $this->setRequestData('locale', self::getLocale(Session::getInstance()->Language()->getIso(), Session::getInstance()->Customer()->cLand))
            ->setRequestData('amount', Amount::factory($this->getBestellung()->fGesamtsummeKundenwaehrung, $this->getBestellung()->Waehrung->cISO, true))
            ->setRequestData('orderNumber', $this->getBestellung()->cBestellNr)
            ->setRequestData('metadata', [
                'kBestellung' => $this->getBestellung()->kBestellung,
                'kKunde' => $this->getBestellung()->kKunde,
                'kKundengruppe' => Session::getInstance()->CustomerGroup()->kKundengruppe,
                'cHash' => $this->getHash(),
            ])
            ->setRequestData('redirectUrl', $this->PaymentMethod()->getReturnURL($this->getBestellung()))
            ->setRequestData('webhookUrl', Shop::getURL(true) . '/?mollie=1');

        $pm = $this->PaymentMethod();
        if ($pm::METHOD !== '' && (self::Plugin()->oPluginEinstellungAssoc_arr['resetMethod'] !== 'on' || !$this->getMollie())) {
            $this->setRequestData('method', $pm::METHOD);
        }

        $this->setRequestData('billingAddress', Address::factory($this->getBestellung()->oRechnungsadresse));
        if ($this->getBestellung()->Lieferadresse !== null) {
            if (!$this->getBestellung()->Lieferadresse->cMail) {
                $this->getBestellung()->Lieferadresse->cMail = $this->getBestellung()->oRechnungsadresse->cMail;
            }
            $this->setRequestData('shippingAddress', Address::factory($this->getBestellung()->Lieferadresse));
        }

        if (
            !empty(Session::getInstance()->Customer()->dGeburtstag)
            && Session::getInstance()->Customer()->dGeburtstag !== '0000-00-00'
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim(Session::getInstance()->Customer()->dGeburtstag))
        ) {
            $this->setRequestData('consumerDateOfBirth', trim(Session::getInstance()->Customer()->dGeburtstag));
        }

        $lines = [];
        foreach ($this->getBestellung()->Positionen as $oPosition) {
            $lines[] = OrderLine::factory($oPosition, $this->getBestellung()->Waehrung);
        }

        if ($this->getBestellung()->GuthabenNutzen && $this->getBestellung()->fGuthaben > 0) {
            $lines[] = OrderLine::getCredit($this->getBestellung());
        }

        if ($comp = OrderLine::getRoundingCompensation($lines, $this->RequestData('amount'), $this->getBestellung()->Waehrung->cISO)) {
            $lines[] = $comp;
        }
        $this->setRequestData('lines', $lines);

        if ($dueDays = $this->PaymentMethod()->getExpiryDays()) {
            $max = $this->RequestData('method') && strpos($this->RequestData('method'), 'klarna') !== false ? 28 : 100;
            $this->setRequestData('expiresAt', date('Y-m-d', strtotime(sprintf("+%d DAYS", min($dueDays, $max)))));
        }

        $this->setRequestData('payment', $options);

        return $this;
    }

    public function getIncomingPayment()
    {
        if (!$this->getMollie()) {
            return null;
        }
        /** @var Payment $payment */
        foreach ($this->getMollie()->payments() as $payment) {
            if (in_array($payment->status,
                [PaymentStatus::STATUS_AUTHORIZED, PaymentStatus::STATUS_PAID], true)) {
                $this->payment = $payment;
                return (object)[
                    'fBetrag' => (float)$payment->amount->value,
                    'cISO' => $payment->amount->currency,
                    'cZahler' => $payment->details->paypalPayerId ?: $payment->customerId,
                    'cHinweis' => $payment->details->paypalReference ?: $payment->id,
                ];
            }
        }
        return null;
    }
}