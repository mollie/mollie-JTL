<?php

require_once __DIR__ . '/JTLMollie.php';

class JTLMollieBitcoin extends JTLMollie
{

    const MOLLIE_METHOD = \Mollie\Api\Types\PaymentMethod::BITCOIN;

}