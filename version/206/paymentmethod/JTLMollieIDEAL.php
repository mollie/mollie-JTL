<?php
/**
 * @copyright 2022 WebStollen GmbH
 * @link https://www.webstollen.de
 */

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/JTLMollie.php';

class JTLMollieIDEAL extends JTLMollie
{
    const ALLOW_PAYMENT_BEFORE_ORDER = true;

    const ALLOW_AUTO_STORNO = true;

    const METHOD = \Mollie\Api\Types\PaymentMethod::IDEAL;
}
