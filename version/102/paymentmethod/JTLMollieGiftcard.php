<?php

require_once __DIR__ . '/JTLMollie.php';

class JTLMollieGiftcard extends JTLMollie
{
    const MOLLIE_METHOD = \Mollie\Api\Types\PaymentMethod::GIFTCARD;
}
