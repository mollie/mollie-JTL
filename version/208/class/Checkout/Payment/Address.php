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
        $resource->streetAndNumber = html_entity_decode($address->cStrasse . ' ' . $address->cHausnummer);
        $resource->postalCode      = html_entity_decode($address->cPLZ);
        $resource->city            = html_entity_decode($address->cOrt);
        $resource->country         = html_entity_decode($address->cLand);

        if (
            isset($address->cAdressZusatz)
            && trim($address->cAdressZusatz) !== ''
        ) {
            $resource->streetAdditional = html_entity_decode(trim($address->cAdressZusatz));
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
