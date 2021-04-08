<?php

use ws_mollie\Hook\ApplePay;

require_once __DIR__ . '/JTLMollie.php';

class JTLMollieApplePay extends JTLMollie
{
    const METHOD = \Mollie\Api\Types\PaymentMethod::APPLEPAY;

    public function isSelectable()
    {
        return ApplePay::isAvailable() && parent::isSelectable();
    }
}
