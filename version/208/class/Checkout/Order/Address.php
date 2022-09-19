<?php

/**
 * @copyright 2022 WebStollen GmbH
 * @link https://www.webstollen.de
 */

namespace ws_mollie\Checkout\Order;

use Shop;
use ws_mollie\Checkout\Exception\ResourceValidityException;

/**
 * Class Address
 * @package ws_mollie\Checkout\Order
 *
 * @property null|string $organizationName
 * @property null|string $title
 * @property string $givenName
 * @property string $familyName
 * @property string $email
 * @property null|string $phone (E.164 Format);
 */
class Address extends \ws_mollie\Checkout\Payment\Address
{
    public static function factory($address)
    {
        $resource = parent::factory($address);

        $resource->title      = html_entity_decode(substr(trim(($address->cAnrede === 'm' ? Shop::Lang()->get('mr') : Shop::Lang()->get('mrs')) . ' ' . $address->cTitel) ?: null, 0, 20));
        $resource->givenName  = html_entity_decode($address->cVorname);
        $resource->familyName = html_entity_decode($address->cNachname);
        $resource->email      = html_entity_decode($address->cMail) ?: null;

        if ($organizationName = trim($address->cFirma)) {
            $resource->organizationName = html_entity_decode($organizationName);
        }

        // Validity-Check
        // TODO: Phone, with E.164 check
        // TODO: Is Email-Format Check needed?
        if (!$resource->givenName || !$resource->familyName || !$resource->email) {
            throw ResourceValidityException::trigger(ResourceValidityException::ERROR_REQUIRED, ['givenName', 'familyName', 'email'], $resource);
        }

        return $resource;
    }
}
