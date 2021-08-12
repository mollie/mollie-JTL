<?php
/**
 * @copyright 2021 WebStollen GmbH
 * @link https://www.webstollen.de
 */

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/JTLMollie.php';

class JTLMolliePaysafecard extends JTLMollie
{
    const METHOD = \Mollie\Api\Types\PaymentMethod::PAYSAFECARD;

    const ALLOW_AUTO_STORNO = true;

    public function getPaymentOptions(Bestellung $order, $apiType)
    {
        return $apiType === 'payment' ? ['customerReference' => $order->oKunde->kKunde] : [];
    }
}
