<?php
/**
 * @copyright 2021 WebStollen GmbH
 * @link https://www.webstollen.de
 */

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/JTLMollie.php';

class JTLMollieKlarnaSliceIt extends JTLMollie
{
    const MAX_EXPIRY_DAYS = 28;

    const METHOD = \Mollie\Api\Types\PaymentMethod::KLARNA_SLICE_IT;
}
