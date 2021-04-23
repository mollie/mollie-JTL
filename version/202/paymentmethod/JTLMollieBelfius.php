<?php

require_once __DIR__ . '/JTLMollie.php';

class JTLMollieBelfius extends JTLMollie
{
    const METHOD = \Mollie\Api\Types\PaymentMethod::BELFIUS;
}
