<?php
require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/JTLMollie.php';

class JTLMollieMyBank extends JTLMollie
{
    const METHOD = \Mollie\Api\Types\PaymentMethod::MYBANK;
}
