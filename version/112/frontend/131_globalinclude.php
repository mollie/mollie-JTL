<?php

use ws_mollie\Checkout\AbstractCheckout;
use ws_mollie\Helper;

//if (strpos($_SERVER['PHP_SELF'], 'bestellabschluss') === false) {
//    return;
//}

require_once __DIR__ . '/../class/Helper.php';


try {
    Helper::init();

    ifndef('MOLLIE_QUEUE_MAX', 3);
    // TODO
    //Queue::run(MOLLIE_QUEUE_MAX);
    AbstractCheckout::sendReminders();

} catch (Exception $e) {
    Helper::logExc($e);
}


