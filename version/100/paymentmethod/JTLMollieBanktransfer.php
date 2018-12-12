<?php

require_once __DIR__ . '/JTLMollie.php';

class JTLMollieBanktransfer extends JTLMollie {
    
    const MOLLIE_METHOD = \Mollie\Api\Types\PaymentMethod::BANKTRANSFER;
    
}