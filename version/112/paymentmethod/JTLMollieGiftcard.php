<?php

require_once __DIR__ . '/JTLMollie.php';

class JTLMollieGiftcard extends JTLMollie
{
    const METHOD = \Mollie\Api\Types\PaymentMethod::GIFTCARD;
}
