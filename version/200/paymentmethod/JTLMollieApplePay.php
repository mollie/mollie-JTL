<?php

/**
 * @copyright 2022 WebStollen GmbH
 * @link https://www.webstollen.de
 */

use ws_mollie\Hook\ApplePay;

require_once __DIR__ . '/../class/Helper.php';
require_once __DIR__ . '/JTLMollie.php';

class JTLMollieApplePay extends JTLMollie
{
    const METHOD = \Mollie\Api\Types\PaymentMethod::APPLEPAY;

    public function isSelectable()
    {
        \ws_mollie\Helper::init();

        return ApplePay::isAvailable() && parent::isSelectable();
    }
}
