<?php
/**
 * @copyright 2021 WebStollen GmbH
 * @link https://www.webstollen.de
 */

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/JTLMollie.php';

class JTLMollieKlarnaPayLater extends JTLMollie
{
    const MAX_EXPIRY_DAYS = 28;

    const ALLOW_PAYMENT_BEFORE_ORDER = true;

    const ALLOW_AUTO_STORNO = true;

    const METHOD = \Mollie\Api\Types\PaymentMethod::KLARNA_PAY_LATER;
}
