<?php

require_once __DIR__ . '/JTLMollie.php';

class JTLMollieEPS extends JTLMollie
{
    const ALLOW_PAYMENT_BEFORE_ORDER = true;

    const ALLOW_AUTO_STORNO = true;

    const METHOD = \Mollie\Api\Types\PaymentMethod::EPS;
}
