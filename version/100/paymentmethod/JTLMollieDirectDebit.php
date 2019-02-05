<?php

require_once __DIR__ . '/JTLMollie.php';

class JTLMollieDirectDebit extends JTLMollie
{

    const MOLLIE_METHOD = \Mollie\Api\Types\PaymentMethod::DIRECTDEBIT;

}