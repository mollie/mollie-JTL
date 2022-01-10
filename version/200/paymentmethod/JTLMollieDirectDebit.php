<?php
/**
 * @copyright 2022 WebStollen GmbH
 * @link https://www.webstollen.de
 */

require_once __DIR__ . '/JTLMollie.php';

/**
 * Class JTLMollieDirectDebit
 * @deprecated since 112
 */
class JTLMollieDirectDebit extends JTLMollie
{
    const METHOD = \Mollie\Api\Types\PaymentMethod::DIRECTDEBIT;

    public function isSelectable()
    {
        return false;
    }

    public function isValidIntern($args_arr = [])
    {
        return false;
    }

    public function isValid($customer, $cart)
    {
        return false;
    }
}
