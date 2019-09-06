<?php

/**
 * Da Webhook nicht 100% sicher vor dem Redirekt ausgeführt wird:
 * - IF Bestellung bereits abgesclossen ? => Update Payment, stop skript
 * - ELSE weiter mit der notify.php
 */

use Mollie\Api\Types\PaymentStatus;
use ws_mollie\Helper;
use ws_mollie\Model\Payment;
use ws_mollie\Mollie;

if (array_key_exists('hash', $_REQUEST)) {
    require_once __DIR__ . '/../class/Helper.php';
    try {
        Helper::init();
        $payment = Payment::getPaymentHash($_REQUEST['hash']);
        // If Bestellung already exists, treat as Notification
        if ($payment && $payment->kBestellung) {
            require_once __DIR__ . '/../paymentmethod/JTLMollie.php';
            $order = JTLMollie::API()->orders->get($payment->kID, ['embed' => 'payments']);
            $logData = '#' . $payment->kBestellung . '$' . $payment->kID;
            Mollie::JTLMollie()->doLog('Received Notification<br/><pre>' . print_r([$order, $payment], 1) . '</pre>', $logData);
            Mollie::handleOrder($order, $payment->kBestellung);
            // exit to stop execution of notify.php

            // GET NEWEST PAYMENT:
            /** @var \Mollie\Api\Resources\Payment $_payment */
            $_payment = null;
            if ($order->payments()) {
                /** @var \Mollie\Api\Resources\Payment $p */
                foreach ($order->payments() as $p) {
                    if (!in_array($p->status, [PaymentStatus::STATUS_AUTHORIZED, PaymentStatus::STATUS_PAID, PaymentStatus::STATUS_PENDING])) {
                        continue;
                    }
                    if (!$_payment) {
                        $_payment = $p;
                        continue;
                    }
                    if (strtotime($p->createdAt) > strtotime($_payment->createdAt)) {
                        $_payment = $p;
                    }
                }
            }

            if ($payment->oBestellung instanceof Bestellung) {
                JTLMollie::API()->performHttpCall('PATCH', sprintf('payments/%s', $_payment->id), json_encode(['description' => $payment->oBestellung->cBestellNr]));
            }

            exit();
        }
    } catch (Exception $e) {
        Helper::logExc($e);
    }
}
