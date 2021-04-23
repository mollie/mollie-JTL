<?php

require_once __DIR__ . '/JTLMollie.php';

class JTLMollieEPS extends JTLMollie
{
    const METHOD = \Mollie\Api\Types\PaymentMethod::EPS;
}
