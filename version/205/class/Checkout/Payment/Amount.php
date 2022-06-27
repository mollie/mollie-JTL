<?php

/**
 * @copyright 2022 WebStollen GmbH
 * @link https://www.webstollen.de
 */

namespace ws_mollie\Checkout\Payment;

use Shop;
use stdClass;
use ws_mollie\Checkout\AbstractResource;
use ws_mollie\Checkout\Exception\ResourceValidityException;

/**
 * Class Amount
 * @package ws_mollie\Checkout\Payment
 *
 * @property string $currency ISO 4217
 * @property string $value
 */
class Amount extends AbstractResource
{
    /**
     * @param float       $value
     * @param null|string $currency
     * @param false       $useRounding (is it total SUM => true [5 Rappen Rounding])
     * @return Amount
     */
    public static function factory($value, $currency = null, $useRounding = false)
    {
        if (!$currency) {
            $currency = static::FallbackCurrency()->cISO;
        }

        $resource = new static();

        $resource->currency = $currency;
        //$resource->value = number_format(round($useRounding ? $resource->round($value * (float)$currency->fFaktor) : $value * (float)$currency->fFaktor, 2), 2, '.', '');
        $resource->value = number_format(round($useRounding ? $resource->round($value) : $value, 2), 2, '.', '');

        // Validity Check
        // TODO: Check ISO Code?
        // TODO: Check Value
        if (!$resource->currency || !$resource->value) {
            throw ResourceValidityException::trigger(ResourceValidityException::ERROR_REQUIRED, ['currency', 'value'], $resource);
        }

        return $resource;
    }

    /**
     * @return stdClass
     */
    public static function FallbackCurrency()
    {
        return isset($_SESSION['Waehrung']) ? $_SESSION['Waehrung'] : Shop::DB()->select('twaehrung', 'cStandard', 'Y');
    }

    /**
     * Check if 5 Rappen rounding is necessary
     *
     * @param mixed $value
     * @return float
     */
    protected function round($value)
    {
        $conf = Shop::getSettings([CONF_KAUFABWICKLUNG]);
        if (isset($conf['kaufabwicklung']['bestellabschluss_runden5']) && (int)$conf['kaufabwicklung']['bestellabschluss_runden5'] === 1) {
            $value = round($value * 20) / 20;
        }

        return $value;
    }
}
