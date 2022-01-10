<?php
/**
 * @copyright 2022 WebStollen GmbH
 * @link https://www.webstollen.de
 */

use ws_mollie\Checkout\Payment\Address;

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/JTLMollie.php';

class JTLMolliePayPal extends JTLMollie
{
    const METHOD = \Mollie\Api\Types\PaymentMethod::PAYPAL;

    const ALLOW_PAYMENT_BEFORE_ORDER = true;

    const ALLOW_AUTO_STORNO = true;

    /**
     * @param Bestellung $order
     * @param $apiType
     * @return array
     */
    public function getPaymentOptions(Bestellung $order, $apiType)
    {
        $paymentOptions = [];

        if ($apiType === 'payment') {
            if ($order->Lieferadresse !== null) {
                if (!$order->Lieferadresse->cMail) {
                    $order->Lieferadresse->cMail = $order->oRechnungsadresse->cMail;
                }
                $paymentOptions['shippingAddress'] = Address::factory($order->Lieferadresse);
            }
            $paymentOptions['description'] = 'Order ' . $order->cBestellNr;
        }


        return $paymentOptions;
    }
}
