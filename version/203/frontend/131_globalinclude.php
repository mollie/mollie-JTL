<?php

use ws_mollie\Checkout\AbstractCheckout;
use ws_mollie\Helper;
use ws_mollie\Queue;

require_once __DIR__ . '/../class/Helper.php';

try {
    Helper::init();

    if (isAjaxRequest()) {
        return;
    }


    ifndef('MOLLIE_QUEUE_MAX', 3);
    Queue::run(MOLLIE_QUEUE_MAX);


    Queue::storno((int)Helper::getSetting('autoStorno'));

    ifndef('MOLLIE_REMINDER_PROP', 10);
    if (mt_rand(1, MOLLIE_REMINDER_PROP) % MOLLIE_REMINDER_PROP === 0) {
        $lock = new \ws_mollie\ExclusiveLock('mollie_reminder', PFAD_ROOT . PFAD_COMPILEDIR);
        if ($lock->lock()) {
            // TODO: Doku!
            AbstractCheckout::sendReminders();
            $lock->unlock();
        }

    }


} catch (Exception $e) {
    Helper::logExc($e);
}


