<?php

/**
 * @copyright 2022 WebStollen GmbH
 * @link https://www.webstollen.de
 */

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/JTLMollie.php';

if (!defined('MOLLIE_KLARNA_MAX_EXPIRY_LIMIT')) {
    define('MOLLIE_KLARNA_MAX_EXPIRY_LIMIT', 28);
}

class JTLMollieKlarnaPayLater extends JTLMollie
{
    const MAX_EXPIRY_DAYS = MOLLIE_KLARNA_MAX_EXPIRY_LIMIT;

    const ALLOW_PAYMENT_BEFORE_ORDER = true;

    const ALLOW_AUTO_STORNO = true;

    const METHOD = \Mollie\Api\Types\PaymentMethod::KLARNA_PAY_LATER;
}
