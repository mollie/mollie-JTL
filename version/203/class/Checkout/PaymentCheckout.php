<?php

/**
 * @copyright 2022 WebStollen GmbH
 * @link https://www.webstollen.de
 */

namespace ws_mollie\Checkout;

use Exception;
use Mollie\Api\Exceptions\ApiException;
use Mollie\Api\Exceptions\IncompatiblePlatform;
use Mollie\Api\Resources\Payment;
use Mollie\Api\Types\PaymentStatus;
use RuntimeException;
use Shop;
use ws_mollie\Checkout\Payment\Address;
use ws_mollie\Checkout\Payment\Amount;

/**
 * Class PaymentCheckout
 * @package ws_mollie\Checkout
 *
 * @property string $locale
 * @property Amount $amount
 * @property string $description
 * @property null|array $metadata
 * @property string $redirectUrl
 * @property string $webhookUrl
 * @property null|string $method
 * @property Address $billingAddress
 * @property null|string $expiresAt
 */
class PaymentCheckout extends AbstractCheckout
{
    /**
     * @var null|Payment
     */
    protected $_payment;

    /**
     * @param PaymentCheckout $checkout
     * @throws ApiException
     * @throws IncompatiblePlatform
     * @return Payment
     */
    public static function cancel($checkout)
    {
        if (!$checkout->getMollie()->isCancelable) {
            throw new RuntimeException('Zahlung kann nicht abgebrochen werden.');
        }
        $payment = $checkout->API()->Client()->payments->cancel($checkout->getMollie()->id);
        $checkout->Log('Zahlung wurde manuell abgebrochen.');

        return $payment;
    }

    /**
     * @param false $force
     * @return null|Payment
     */
    public function getMollie($force = false)
    {
        if ($force || (!$this->getPayment() && $this->getModel()->kID)) {
            try {
                $this->setPayment($this->API()->Client()->payments->get($this->getModel()->kID, ['embed' => 'refunds']));
            } catch (Exception $e) {
                throw new RuntimeException('Mollie-Payment konnte nicht geladen werden: ' . $e->getMessage());
            }
        }

        return $this->getPayment();
    }

    /**
     * @return null|Payment
     */
    public function getPayment()
    {
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
     * @param mixed $force
     * @throws ApiException
     * @throws IncompatiblePlatform
     * @return string
     */
    public function cancelOrRefund($force = false)
    {
        if (!$this->getMollie()) {
            throw new RuntimeException('Mollie-Order konnte nicht geladen werden: ' . $this->getModel()->kID);
        }
        if ($force || (int)$this->getBestellung()->cStatus === BESTELLUNG_STATUS_STORNO) {
            if ($this->getMollie()->isCancelable) {
                $res    = $this->API()->Client()->payments->cancel($this->getMollie()->id);
                $result = 'Payment cancelled, Status: ' . $res->status;
            } else {
                $res    = $this->API()->Client()->payments->refund($this->getMollie(), ['amount' => $this->getMollie()->amount]);
                $result = 'Payment Refund initiiert, Status: ' . $res->status;
            }
            $this->PaymentMethod()->Log('PaymentCheckout::cancelOrRefund: ' . $result, $this->LogData());

            return $result;
        }

        throw new RuntimeException('Bestellung ist derzeit nicht storniert, Status: ' . $this->getBestellung()->cStatus);
    }

    /**
     * @param array $paymentOptions
     * @return Payment
     */
    public function create(array $paymentOptions = [])
    {
        if ($this->getModel()->kID) {
            try {
                $this->setPayment($this->API()->Client()->payments->get($this->getModel()->kID));
                if ($this->getPayment()->status === PaymentStatus::STATUS_PAID) {
                    throw new RuntimeException(self::Plugin()->oPluginSprachvariableAssoc_arr['errAlreadyPaid']);
                }
                if ($this->getPayment()->status === PaymentStatus::STATUS_OPEN) {
                    $this->updateModel()->saveModel();

                    return $this->getPayment();
                }
            } catch (RuntimeException $e) {
                //$this->Log(sprintf("PaymentCheckout::create: Letzte Transaktion '%s' konnte nicht geladen werden: %s", $this->getModel()->kID, $e->getMessage()), LOGLEVEL_ERROR);
                throw $e;
            } catch (Exception $e) {
                $this->Log(sprintf("PaymentCheckout::create: Letzte Transaktion '%s' konnte nicht geladen werden: %s, versuche neue zu erstellen.", $this->getModel()->kID, $e->getMessage()), LOGLEVEL_ERROR);
            }
        }

        try {
            $req = $this->loadRequest($paymentOptions)->jsonSerialize();
            $this->setPayment($this->API()->Client()->payments->create($req));
            $this->Log(sprintf("Payment für '%s' wurde erfolgreich angelegt: %s", $this->getBestellung()->cBestellNr, $this->getPayment()->id));
            $this->updateModel()->saveModel();

            return $this->getPayment();
        } catch (Exception $e) {
            $this->Log(sprintf("PaymentCheckout::create: Neue Transaktion für '%s' konnte nicht erstellt werden: %s.", $this->getBestellung()->cBestellNr, $e->getMessage()), LOGLEVEL_ERROR);

            throw new RuntimeException(sprintf('Mollie-Payment \'%s\' konnte nicht geladen werden: %s', $this->getBestellung()->cBestellNr, $e->getMessage()));
        }
    }

    /**
     * @param array $options
     * @return $this|PaymentCheckout
     */
    public function loadRequest(&$options = [])
    {
        parent::loadRequest($options);

        $this->description = 'Order ' . $this->getBestellung()->cBestellNr;

        foreach ($options as $key => $value) {
            $this->$key = $value;
        }

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

        if (in_array($this->getMollie()->status, [PaymentStatus::STATUS_AUTHORIZED, PaymentStatus::STATUS_PAID], true)) {
            $data             = [];
            $data['fBetrag']  = (float)$this->getMollie()->amount->value;
            $data['cISO']     = $this->getMollie()->amount->currency;
            $data['cZahler']  = $this->getMollie()->details->paypalPayerId ?: $this->getMollie()->customerId;
            $data['cHinweis'] = $this->getMollie()->details->paypalReference ?: $this->getMollie()->id;
            if (isset($this->getMollie()->details, $this->getMollie()->details->paypalFee)) {
                $data['fZahlungsgebuehr'] = $this->getMollie()->details->paypalFee->value;
            }

            return (object)$data;
        }

        return null;
    }

    /**
     * @param Payment $model
     * @return $this|AbstractCheckout
     */
    protected function setMollie($model)
    {
        if ($model instanceof Payment) {
            $this->setPayment($model);
        }

        return $this;
    }

    /**
     * @return $this
     */
    protected function updateOrderNumber()
    {
        try {
            if ($this->getMollie()) {
                $this->getMollie()->description = 'Order ' . $this->getBestellung()->cBestellNr;
                $this->getMollie()->webhookUrl  = Shop::getURL() . '/?mollie=1';
                $this->getMollie()->update();
            }
        } catch (Exception $e) {
            $this->Log('OrderCheckout::updateOrderNumber:' . $e->getMessage(), LOGLEVEL_ERROR);
        }

        return $this;
    }
}
