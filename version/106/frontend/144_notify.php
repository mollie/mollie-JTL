<?php

/**
 * Da Webhook nicht 100% sicher vor dem Redirekt ausgeführt wird:
 * - IF Bestellung bereits abgesclossen ? => Update Payment, stop skript
 * - ELSE weiter mit der notify.php
 */


use ws_mollie\Helper;
use ws_mollie\Mollie;

try {

    require_once __DIR__ . '/../class/Helper.php';

    Helper::init();

    require_once __DIR__ . '/../paymentmethod/JTLMollie.php';

    $orderID = array_key_exists('id', $_REQUEST) ? $_REQUEST['id'] : false;
    if (!$orderId) {
        // NOT A MOLLIE NOTIFICATION!
        return;
    }
    $sh = array_key_exists('sh', $_REQUEST) ? $_REQUEST['sh'] : false;
    if (!$sh) {
        // NO SESSION HASH GIVEN!
        return;
    }

    $logData = '$' . $orderId;

    if ($oZahlungSession = JTLMollie::getZahlungSession(md5(trim($sh, '_')))) {
        if ((int)$oZahlungSession->kBestellung <= 0) {
            // Bestellung noch nicht abgeschlossen, weiter mit standard
            Mollie::JTLMollie()->doLog("Hook 144: orderId open, finalize with shop standard: {$orderId} / {$oZahlungSession->cZahlungsID}", $logData, LOGLEVEL_NOTICE);
            return;
        }

        if (trim($oZahlungSession->cNotifyID) === trim($orderId)) {
            $logData = '$' . $oZahlungSession->cNotifyID;
            Mollie::JTLMollie()->doLog("Hook 144: order finalized already => handleNotification", $logData, LOGLEVEL_NOTICE);
            // Bestellung bereits finalisiert => evtl. Statusänderung
            $oOrder = JTLMollie::API()->orders->get($orderId, ['embed' => 'payments']);
            Mollie::handleOrder($oOrder, (int)$oZahlungSession->kBestellung);
            exit();
        } else {
            Mollie::JTLMollie()->doLog("Hook 144: orderId invalid: {$orderId} / {$oZahlungSession->cNotifyID}", $logData, LOGLEVEL_ERROR);
        }
    } else {
        Mollie::JTLMollie()->doLog("Hook 144: couldn't load ZahlungSession => {$sh}", $logData, LOGLEVEL_DEBUG);
    }

} catch (Exception $e) {
    Helper::logExc($e);
}
