<?php

/**
 * Da Webhook nicht 100% sicher vor dem Redirekt ausgeführt wird:
 * - IF Bestellung bereits abgesclossen ? => Update Payment, stop skript
 * - ELSE weiter mit der
 */

if (array_key_exists('hash', $_REQUEST)) {
    require_once __DIR__ . '/../class/Helper.php';
    try {
        \ws_mollie\Helper::init();

        $payment = \ws_mollie\Model\Payment::getPaymentHash($_REQUEST['hash']);

        if ($payment && $payment->kBestellung) {

            $mollie = new \Mollie\Api\MollieApiClient();
            $mollie->setApiKey(\ws_mollie\Helper::getSetting("api_key"));
            $order = $mollie->orders->get($payment->kID);
            $oBestellung = new Bestellung($payment->kBestellung);
            $order->orderNumber = $oBestellung->cBestellNr;
            \ws_mollie\Model\Payment::updateFromPayment($order, $payment->kBestellung);

            $logData = '#' . $payment->kBestellung . '$' . $payment->kID . "§" . $oBestellung->cBestellNr;
            \ws_mollie\Mollie::JTLMollie()->doLog('Received Notification<br/><pre>' . print_r([$order, $payment], 1) . '</pre>', $logData);

            switch ($order->status) {
                case \Mollie\Api\Types\OrderStatus::STATUS_COMPLETED:
                case \Mollie\Api\Types\OrderStatus::STATUS_PAID:
                    \ws_mollie\Mollie::JTLMollie()->doLog('PaymentStatus: ' . $order->status . ' => Zahlungseingang (' . $order->amount->value . ')', $logData, LOGLEVEL_DEBUG);
                    $oIncomingPayment = new stdClass();
                    $oIncomingPayment->fBetrag = $order->amount->value;
                    $oIncomingPayment->cISO = $order->amount->curreny;
                    $oIncomingPayment->cHinweis = $order->id;
                    \ws_mollie\Mollie::JTLMollie()->addIncomingPayment($oBestellung, $oIncomingPayment);
                case \Mollie\Api\Types\OrderStatus::STATUS_AUTHORIZED:
                    \ws_mollie\Mollie::JTLMollie()->doLog('PaymentStatus: ' . $order->status . ' => Bestellung bezahlt', $logData, LOGLEVEL_DEBUG);
                    \ws_mollie\Mollie::JTLMollie()->setOrderStatusToPaid($oBestellung);
                    break;
            }
            // stop notify.php script
            exit();
        }
    } catch (Exception $e) {
        \ws_mollie\Helper::logExc($e);
    }
}
