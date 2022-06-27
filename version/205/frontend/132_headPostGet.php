<?php

/**
 * @copyright 2022 WebStollen GmbH
 * @link https://www.webstollen.de
 */

use ws_mollie\Helper;
use ws_mollie\Hook\Queue;

require_once __DIR__ . '/../class/Helper.php';


try {
    Helper::init();

    Queue::headPostGet();
} catch (Exception $e) {
    Helper::logExc($e);
}
