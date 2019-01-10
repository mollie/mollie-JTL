<?php
require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/JTLMollie.php';

class JTLMolliePayPal extends JTLMollie
{
    const MOLLIE_METHOD = \Mollie\Api\Types\PaymentMethod::PAYPAL;

}