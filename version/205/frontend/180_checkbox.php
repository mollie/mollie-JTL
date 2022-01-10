<?php
/**
 * @copyright 2022 WebStollen GmbH
 * @link https://www.webstollen.de
 */

use ws_mollie\Helper;

require_once __DIR__ . '/../class/Helper.php';


try {
    Helper::init();

    if (Helper::oPlugin()->oPluginEinstellungAssoc_arr['useCustomerAPI'] === 'C') {
        \ws_mollie\Hook\Checkbox::execute($args_arr);
    }
} catch (Exception $e) {
    Helper::logExc($e);
}
