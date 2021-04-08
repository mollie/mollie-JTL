<?php

use ws_mollie\Helper;
use ws_mollie\Hook\ApplePay;

require_once __DIR__ . '/../class/Helper.php';

if (!Helper::init()) {
    return;
}

require_once __DIR__ . '/../../../../../globalinclude.php';

if (array_key_exists('available', $_REQUEST)) {
    ApplePay::setAvailable((bool)$_REQUEST['available']);
}
header('Content-Type: application/json');
echo json_encode(true);
