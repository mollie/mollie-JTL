<?php

use Mollie\Api\Resources\Payment;
use Mollie\Api\Types\PaymentStatus;
use ws_mollie\Helper;
use ws_mollie\Mollie;

if (strpos($_SERVER['PHP_SELF'], 'bestellabschluss') === false) {
    return;
}
require_once __DIR__ . '/../class/Helper.php';
try {
    Helper::init();
    // suppress any output, for redirect
    ob_start();
    if (array_key_exists('mollie', $_REQUEST)) {
        $payment = Shop::DB()->executeQueryPrepared("SELECT * FROM " . \ws_mollie\Model\Payment::TABLE . " WHERE cHash = :cHash", [':cHash' => $_REQUEST['mollie']], 1);
        // Bestellung finalized, redirect to status/completion page
        if ((int)$payment->kBestellung) {
            $logData = '$' . $payment->kID . '#' . $payment->kBestellung . "§" . $payment->cOrderNumber;
            Mollie::JTLMollie()->doLog('Hook 131/kBestellung => bestellabschluss', $logData);
            $order = JTLMollie::API()->orders->get($payment->kID, ['embed' => 'payments']);
            Mollie::handleOrder($order, $payment->kBestellung);
            Mollie::getOrderCompletedRedirect($payment->kBestellung, true);
        } elseif ($payment) { // payment, but no order => finalize it
            require_once __DIR__ . '/../paymentmethod/JTLMollie.php';
            $order = JTLMollie::API()->orders->get($payment->kID, ['embed' => 'payments']);
            $logData = '$' . $order->id . "§" . $payment->cOrderNumber;
            Mollie::JTLMollie()->doLog('Hook 131/open => finalize?', $logData);


            // GET NEWEST PAYMENT:
            /** @var Payment $_payment */
            $_payment = null;
            if ($order->payments()) {
                /** @var Payment $p */
                foreach ($order->payments() as $p) {
                    if (!$_payment) {
                        $_payment = $p;
                        continue;
                    }
                    if (strtotime($p->createdAt) > strtotime($_payment->createdAt)) {
                        $_payment = $p;
                    }
                }
            }

            // finalize only, if order is not canceld/expired
            if ($order && !$order->isCanceled() && !$order->isExpired()) {
                // finalize only if payment is not expired/canceled,failed or open
                if ($_payment && !in_array($_payment->status, [PaymentStatus::STATUS_EXPIRED, PaymentStatus::STATUS_CANCELED, PaymentStatus::STATUS_OPEN, PaymentStatus::STATUS_FAILED])) {

                    Mollie::JTLMollie()->doLog('Hook 131/open => finalize!', $logData, LOGLEVEL_DEBUG);
                    /** @noinspection PhpIncludeInspection */
                    require_once PFAD_ROOT . 'includes/bestellabschluss_inc.php';
                    /** @noinspection PhpIncludeInspection */
                    require_once PFAD_ROOT . 'includes/mailTools.php';
                    $session = Session::getInstance();
                    $oBestellung = fakeBestellung();
                    $oBestellung = finalisiereBestellung();
                    $session->cleanUp();

                    if ($oBestellung->kBestellung > 0 && array_key_exists('cMollieHash', $_SESSION)) {
                        $_upd = new stdClass();
                        $_upd->nBezahlt = 1;
                        $_upd->dZeitBezahlt = 'now()';
                        $_upd->kBestellung = (int)$oBestellung->kBestellung;
                        Shop::DB()->update('tzahlungsession', 'cZahlungsID', $_SESSION['cMollieHash'], $_upd);
                        unset($_SESSION['cMollieHash']);
                        Jtllog::writeLog('tzahlungsession aktualisiert.', JTLLOG_LEVEL_DEBUG, false, 'Notify');
                    }

                    Mollie::JTLMollie()->doLog('Hook 131/finalized => bestellabschluss<br/><pre>' . print_r($order, 1) . '</pre>', $logData);
                    Mollie::handleOrder($order, $oBestellung->kBestellung);
                    JTLMollie::API()->performHttpCall('PATCH', sprintf('payments/%s', $_payment->id), json_encode(['description' => $oBestellung->cBestellNr]));

                    return Mollie::getOrderCompletedRedirect($oBestellung->kBestellung, true);

                } else {
                    Mollie::JTLMollie()->doLog('Hook 131/Invalid Payment<br/><pre>' . print_r($payment, 1) . '</pre>', $logData, LOGLEVEL_ERROR);
                    header('Location: ' . Shop::getURL() . '/bestellvorgang.php?editZahlungsart=1&mollieStatus=' . $_payment->status);
                    exit();
                }
            } else {
                Mollie::JTLMollie()->doLog('Hook 131/Invalid Order<br/><pre>' . print_r($order, 1) . '</pre>', $logData, LOGLEVEL_ERROR);
                header('Location: ' . Shop::getURL() . '/bestellvorgang.php?editZahlungsart=1&mollieStatus=' . $order->status);
                exit();
            }
        }
    }
    ob_end_flush();
} catch (Exception $e) {
    Helper::logExc($e);
}
