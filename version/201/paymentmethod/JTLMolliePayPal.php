<?php

use Mollie\Api\Exceptions\ApiException;
use Mollie\Api\Exceptions\IncompatiblePlatform;
use ws_mollie\Checkout\Payment\Address;

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/JTLMollie.php';

class JTLMolliePayPal extends JTLMollie
{
    const METHOD = \Mollie\Api\Types\PaymentMethod::PAYPAL;

    /**
     * @throws ApiException
     * @throws IncompatiblePlatform
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
