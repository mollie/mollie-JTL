<?php

use ws_mollie\Helper;


require_once __DIR__ . '/../class/Helper.php';


try {
    Helper::init();

    if (Helper::oPlugin()->oPluginEinstellungAssoc_arr['useCustomerAPI'] === 'C') {
        // TODO
        // Checkbox::execute(isset($args_arr) ? $args_arr : []);
    }

} catch (Exception $e) {
    Helper::logExc($e);
}