<?php
/**
 * Created by PhpStorm.
 * User: proske
 * Date: 2018-12-12
 * Time: 10:00
 */

require_once __DIR__ . '/JTLMollie.php';

class JTLMollieKlarnaSliceIt extends JTLMollie
{
    const MOLLIE_METHOD = \Mollie\Api\Types\PaymentMethod::KLARNA_SLICE_IT;

}