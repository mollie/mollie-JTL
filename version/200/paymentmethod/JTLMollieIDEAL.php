<?php

/**
 * @copyright 2022 WebStollen GmbH
 * @link https://www.webstollen.de
 */

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/JTLMollie.php';

class JTLMollieIDEAL extends JTLMollie
{
    const METHOD = \Mollie\Api\Types\PaymentMethod::IDEAL;
}
