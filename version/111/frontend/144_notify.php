<?php

/**
 * @copyright 2022 WebStollen GmbH
 * @link https://www.webstollen.de
 */

use ws_mollie\Helper;
use ws_mollie\Mollie;

try {
    require_once __DIR__ . '/../class/Helper.php';

    Helper::init();

    require_once __DIR__ . '/../paymentmethod/JTLMollie.php';

    $orderId = array_key_exists('id', $_REQUEST) ? $_REQUEST['id'] : false;
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
        if (trim($oZahlungSession->cNotifyID) === '') {
            $oZahlungSession->cNotifyID = $orderId;
            Shop::DB()->update('tzahlungsession', 'cZahlungsID', $oZahlungSession->cZahlungsID, $oZahlungSession);
        }

        if ((int)$oZahlungSession->kBestellung <= 0) {
            // Bestellung noch nicht abgeschlossen, weiter mit standard
            Mollie::JTLMollie()->doLog("Hook 144: orderId open, finalize with shop standard: {$orderId} / {$oZahlungSession->cZahlungsID}", $logData, LOGLEVEL_NOTICE);

            return;
        }

        if (trim($oZahlungSession->cNotifyID) === trim($orderId)) {
            $logData = '$' . $oZahlungSession->cNotifyID;
            Mollie::JTLMollie()->doLog('Hook 144: order finalized already => handleNotification', $logData, LOGLEVEL_NOTICE);
            // Bestellung bereits finalisiert => evtl. Statusänderung
            $oOrder = JTLMollie::API()->orders->get($orderId, ['embed' => 'payments']);
            Mollie::handleOrder($oOrder, (int)$oZahlungSession->kBestellung);
            exit();
        }
        Mollie::JTLMollie()->doLog("Hook 144: orderId invalid: {$orderId} / {$oZahlungSession->cNotifyID}", $logData, LOGLEVEL_ERROR);
    } else {
        Mollie::JTLMollie()->doLog("Hook 144: couldn't load ZahlungSession => {$sh}", $logData, LOGLEVEL_DEBUG);
    }
} catch (Exception $e) {
    Helper::logExc($e);
}
