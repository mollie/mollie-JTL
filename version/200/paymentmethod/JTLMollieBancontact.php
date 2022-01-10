<?php
/**
 * @copyright 2022 WebStollen GmbH
 * @link https://www.webstollen.de
 */

require_once __DIR__ . '/JTLMollie.php';

class JTLMollieBancontact extends JTLMollie
{
    const METHOD = \Mollie\Api\Types\PaymentMethod::BANCONTACT;
}
