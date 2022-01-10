<?php
/**
 * @copyright 2022 WebStollen GmbH
 * @link https://www.webstollen.de
 */

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/JTLMollie.php';

class JTLMolliePrzelewy24 extends JTLMollie
{
    const METHOD = \Mollie\Api\Types\PaymentMethod::PRZELEWY24;

    const ALLOW_PAYMENT_BEFORE_ORDER = true;

    const ALLOW_AUTO_STORNO = true;

    public function getPaymentOptions(Bestellung $order, $apiType)
    {
        return $apiType === 'payment' ? ['billingEmail' => $order->oRechnungsadresse->cMail] : [];
    }
}
