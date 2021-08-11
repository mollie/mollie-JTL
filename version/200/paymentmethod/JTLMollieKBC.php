<?php
/**
 * @copyright 2021 WebStollen GmbH
 * @link https://www.webstollen.de
 */

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/JTLMollie.php';

class JTLMollieKBC extends JTLMollie
{
    const METHOD = \Mollie\Api\Types\PaymentMethod::KBC;

    public function getPaymentOptions(Bestellung $order, $apiType)
    {
        return ['description' => substr($order->cBestellNr, 0, 13)];
    }
}
