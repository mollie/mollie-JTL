<?php

require_once __DIR__ . '/JTLMollie.php';

class JTLMollieBancontact extends JTLMollie
{
    const METHOD = \Mollie\Api\Types\PaymentMethod::BANCONTACT;
}
