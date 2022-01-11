<?php

/**
 * @copyright 2022 WebStollen GmbH
 * @link https://www.webstollen.de
 */

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
        $payment = Shop::DB()->executeQueryPrepared('SELECT * FROM ' . \ws_mollie\Model\Payment::TABLE . ' WHERE cHash = :cHash', [':cHash' => $_REQUEST['mollie']], 1);
        // Bestellung finalized, redirect to status/completion page
        if ((int)$payment->kBestellung) {
            $logData = '$' . $payment->kID . '#' . $payment->kBestellung . '§' . $payment->cOrderNumber;
            Mollie::JTLMollie()->doLog('Bestellung finalized => redirect abschluss/status', $logData);
            $order = JTLMollie::API()->orders->get($payment->kID, ['embed' => 'payments']);
            Mollie::handleOrder($order, $payment->kBestellung);
            Mollie::getOrderCompletedRedirect($payment->kBestellung, true);
        } elseif ($payment) { // payment, but no order => finalize it
            require_once __DIR__ . '/../paymentmethod/JTLMollie.php';
            $order   = JTLMollie::API()->orders->get($payment->kID, ['embed' => 'payments']);
            $logData = '$' . $order->id . '#' . $payment->kBestellung . '§' . $payment->cOrderNumber;

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
                if ($_payment && !in_array($_payment->status, [PaymentStatus::STATUS_EXPIRED, PaymentStatus::STATUS_CANCELED, PaymentStatus::STATUS_OPEN, PaymentStatus::STATUS_FAILED, PaymentStatus::STATUS_CANCELED])) {
                    Mollie::JTLMollie()->doLog('Bestellung open => finalize', $logData);
                    $session = Session::getInstance();
                    /** @noinspection PhpIncludeInspection */
                    require_once PFAD_ROOT . 'includes/bestellabschluss_inc.php';
                    /** @noinspection PhpIncludeInspection */
                    require_once PFAD_ROOT . 'includes/mailTools.php';
                    $oBestellung = fakeBestellung();
                    $oBestellung = finalisiereBestellung();
                    Mollie::JTLMollie()->doLog('Bestellung finalized => redirect<br/><pre>' . print_r($order, 1) . '</pre>', $logData, LOGLEVEL_DEBUG);
                    Mollie::handleOrder($order, $oBestellung->kBestellung);
                    Mollie::getOrderCompletedRedirect($oBestellung->kBestellung, true);
                } else {
                    Mollie::JTLMollie()->doLog('Invalid Payment<br/><pre>' . print_r($payment, 1) . '</pre>', $logData, LOGLEVEL_ERROR);
                }
            } else {
                Mollie::JTLMollie()->doLog('Invalid Order<br/><pre>' . print_r($order, 1) . '</pre>', $logData, LOGLEVEL_ERROR);
            }
        }
    }
    ob_end_flush();
} catch (Exception $e) {
    Helper::logExc($e);
}
