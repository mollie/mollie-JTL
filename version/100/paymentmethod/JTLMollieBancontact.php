<?php

require_once __DIR__ . '/JTLMollie.php';

class JTLMollieBancontact extends JTLMollie {
    
    const MOLLIE_METHOD = \Mollie\Api\Types\PaymentMethod::BANCONTACT;
    
}