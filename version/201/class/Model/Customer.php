<?php


namespace ws_mollie\Model;

/**
 * Class Customer
 * @package ws_mollie\Model
 *
 * @property int $kKunde
 * @property string $customerId
 */
class Customer extends AbstractModel
{

    const PRIMARY = 'kKunde';
    const TABLE = 'xplugin_ws_mollie_kunde';

}