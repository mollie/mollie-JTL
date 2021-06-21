<?php

require_once __DIR__ . '/JTLMollie.php';

class JTLMollieApplePay extends JTLMollie
{
    const MOLLIE_METHOD = \Mollie\Api\Types\PaymentMethod::APPLEPAY;
}
