<?php
require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/JTLMollie.php';

class JTLMolliePrzelewy24 extends JTLMollie
{
    const METHOD = \Mollie\Api\Types\PaymentMethod::PRZELEWY24;


    public function getPaymentOptions(Bestellung $order, $apiType)
    {
        return $apiType === 'payment' ? ['billingEmail' => $order->oRechnungsadresse->cMail] : [];
    }

}
