<?php
/**
 * @copyright 2022 WebStollen GmbH
 * @link https://www.webstollen.de
 */

namespace ws_mollie\Model;

/**
 * Class Shipment
 * @package ws_mollie\Model
 *
 * @property int $kLieferschein
 * @property int $kBestellung
 * @property string $cOrderId
 * @property string $cShipmentId
 * @property string $cCarrier
 * @property string $cCode
 * @property string $cUrl
 * @property string $dCreated
 * @property string $dModified
 */
class Shipment extends AbstractModel
{
    const PRIMARY = 'kLieferschein';

    const TABLE = 'xplugin_ws_mollie_shipments';
}
