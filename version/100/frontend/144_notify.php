<?php

/**
 * Da Webhook nicht 100% sicher vor dem Redirekt ausgeführt wird:
 * - IF Bestellung bereits abgesclossen ? => Update Payment, stop skript
 * - ELSE weiter mit der notify.php
 */

if (array_key_exists('hash', $_REQUEST)) {
    require_once __DIR__ . '/../class/Helper.php';
    try {
        \ws_mollie\Helper::init();
        $payment = \ws_mollie\Model\Payment::getPaymentHash($_REQUEST['hash']);
        // If Bestellung already exists, treat as Notification
        if ($payment && $payment->kBestellung) {
            require_once __DIR__ . '/../paymentmethod/JTLMollie.php';
            $order = JTLMollie::API()->orders->get($payment->kID);
            $oBestellung = new Bestellung($payment->kBestellung);
            $order->orderNumber = $oBestellung->cBestellNr;
            $logData = '#' . $payment->kBestellung . '$' . $payment->kID . "§" . $oBestellung->cBestellNr;
            \ws_mollie\Mollie::JTLMollie()->doLog('Received Notification<br/><pre>' . print_r([$order, $payment], 1) . '</pre>', $logData);
            \ws_mollie\Mollie::handleOrder($order, $payment->kBestellung);
            // exit to stop execution of notify.php
            exit();
        }
    } catch (Exception $e) {
        \ws_mollie\Helper::logExc($e);
    }
}
