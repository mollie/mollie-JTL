<?php

/**
 * @copyright 2022 WebStollen GmbH
 * @link https://www.webstollen.de
 */

namespace ws_mollie\Checkout\Payment;

use ws_mollie\Checkout\AbstractResource;
use ws_mollie\Checkout\Exception\ResourceValidityException;

/**
 * Class Address
 * @package ws_mollie\Checkout\Payment
 * @property string $streetAndNumber
 * @property string $city
 * @property string $country (ISO 3166-1 alpha-2)
 * @property null|string $streetAdditional
 * @property null|string $postalCode
 * @property null|string $region
 */
class Address extends AbstractResource
{
    /**
     * @param $address
     * @return static
     */
    public static function factory($address)
    {
        $resource                  = new static();
        $resource->streetAndNumber = $address->cStrasse . ' ' . $address->cHausnummer;
        $resource->postalCode      = $address->cPLZ;
        $resource->city            = $address->cOrt;
        $resource->country         = $address->cLand;

        if (
            isset($address->cAdressZusatz)
            && trim($address->cAdressZusatz) !== ''
        ) {
            $resource->streetAdditional = trim($address->cAdressZusatz);
        }

        // Validity-Check
        // TODO: Check for valid Country Code?
        // TODO: Check PostalCode requirement Country?
        if (!$resource->streetAndNumber || !$resource->city || !$resource->country) {
            throw ResourceValidityException::trigger(ResourceValidityException::ERROR_REQUIRED, ['streetAndNumber', 'city', 'country'], $resource);
        }

        return $resource;
    }
}
