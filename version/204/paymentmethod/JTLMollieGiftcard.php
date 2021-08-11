<?php
/**
 * @copyright 2021 WebStollen GmbH
 * @link https://www.webstollen.de
 */

require_once __DIR__ . '/JTLMollie.php';

/**
 * Class JTLMollieGiftcard
 * @deprecated
 */
class JTLMollieGiftcard extends JTLMollie
{
    const METHOD = \Mollie\Api\Types\PaymentMethod::GIFTCARD;

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
