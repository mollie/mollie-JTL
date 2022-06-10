<?php

/**
 * @copyright 2022 WebStollen GmbH
 * @link https://www.webstollen.de
 */

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/JTLMollie.php';

class JTLMollieKBC extends JTLMollie
{
    const METHOD = \Mollie\Api\Types\PaymentMethod::KBC;

    const ALLOW_PAYMENT_BEFORE_ORDER = true;

    const ALLOW_AUTO_STORNO = true;

    public function getPaymentOptions(Bestellung $order, $apiType)
    {
        return ['description' => substr($order->cBestellNr, 0, 13)];
    }
}
