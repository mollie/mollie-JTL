<?php

require_once __DIR__ . '/JTLMollie.php';

class JTLMollieEPS extends JTLMollie {
    
    const MOLLIE_METHOD = \Mollie\Api\Types\PaymentMethod::EPS;
    
}