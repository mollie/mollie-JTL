<?php
require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/JTLMollie.php';

class JTLMollieKlarnaPayLater extends JTLMollie
{
    const MAX_EXPIRY_DAYS = 28;

    const METHOD = \Mollie\Api\Types\PaymentMethod::KLARNA_PAY_LATER;
}
