<?php
require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/JTLMollie.php';

class JTLMollieGiropay extends JTLMollie
{
    const METHOD = \Mollie\Api\Types\PaymentMethod::GIROPAY;
}
