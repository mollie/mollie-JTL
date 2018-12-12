<?php

require_once __DIR__ . '/JTLMollie.php';

class JTLMollieCreditCard extends JTLMollie {
    
    const MOLLIE_METHOD = \Mollie\Api\Types\PaymentMethod::CREDITCARD;
    
}