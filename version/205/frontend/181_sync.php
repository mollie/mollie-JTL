<?php

/**
 * @copyright 2021 WebStollen GmbH
 * @link https://www.webstollen.de
 */

use ws_mollie\Helper;
use ws_mollie\Hook\Queue;

try {
    require_once __DIR__ . '/../class/Helper.php';
    Helper::init();

    Queue::xmlBestellStatus(isset($args_arr) ? $args_arr : []);
} catch (Exception $e) {
    Helper::logExc($e);
}
