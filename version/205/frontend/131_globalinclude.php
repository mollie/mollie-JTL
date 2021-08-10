<?php
/**
 * @copyright 2021 WebStollen GmbH
 * @link https://www.webstollen.de
 */

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

    if (array_key_exists('hash', $_REQUEST) && strpos($_SERVER['PHP_SELF'], 'bestellabschluss.php') !== false) {
        $sessionHash    = substr(StringHandler::htmlentities(StringHandler::filterXSS($_REQUEST['hash'])), 1);
        $paymentSession = Shop::DB()->select('tzahlungsession', 'cZahlungsID', $sessionHash);
        if ($paymentSession && $paymentSession->kBestellung) {
            $oBestellung = new Bestellung($paymentSession->kBestellung);

            if (Shopsetting::getInstance()->getValue('kaufabwicklung', 'bestellabschluss_abschlussseite') === 'A') {
                $oZahlungsID = Shop::DB()->query(
                    '
                    SELECT cId 
                        FROM tbestellid 
                        WHERE kBestellung = ' . (int)$paymentSession->kBestellung,
                    1
                );
                if (is_object($oZahlungsID)) {
                    header('Location: ' . Shop::getURL() . '/bestellabschluss.php?i=' . $oZahlungsID->cId);
                    exit();
                }
            }
            $bestellstatus = Shop::DB()->select('tbestellstatus', 'kBestellung', (int)$paymentSession->kBestellung);
            header('Location: ' . Shop::getURL() . '/status.php?uid=' . $bestellstatus->cUID);
            exit();
        }
    }

    ifndef('MOLLIE_REMINDER_PROP', 10);
    if (mt_rand(1, MOLLIE_REMINDER_PROP) % MOLLIE_REMINDER_PROP === 0) {
        $lock = new \ws_mollie\ExclusiveLock('mollie_reminder', PFAD_ROOT . PFAD_COMPILEDIR);
        if ($lock->lock()) {
            // TODO: Doku!

            AbstractCheckout::sendReminders();
            Queue::storno((int)Helper::getSetting('autoStorno'));

            $lock->unlock();
        }
    }
} catch (Exception $e) {
    Helper::logExc($e);
}
