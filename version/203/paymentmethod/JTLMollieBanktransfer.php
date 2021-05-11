<?php

use ws_mollie\Checkout\AbstractCheckout;

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/JTLMollie.php';

class JTLMollieBanktransfer extends JTLMollie
{
    const METHOD = \Mollie\Api\Types\PaymentMethod::BANKTRANSFER;

    const ALLOW_PAYMENT_BEFORE_ORDER = false;

    public function getPaymentOptions(Bestellung $order, $apiType)
    {
        $paymentOptions = [];
        if ($apiType === 'payment') {
            $paymentOptions['billingEmail'] = $order->oRechnungsadresse->cMail;
            $paymentOptions['locale'] = AbstractCheckout::getLocale($_SESSION['cISOSprache'], $order->oRechnungsadresse->cLand);
        }

        $dueDays = $this->getExpiryDays();
        if ($dueDays > 3) {
            $paymentOptions['dueDate'] = date('Y-m-d', strtotime("+{$dueDays} DAYS"));
        }
        return $paymentOptions;
    }
}
