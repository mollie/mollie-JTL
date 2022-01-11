<?php

/**
 * @copyright 2022 WebStollen GmbH
 * @link https://www.webstollen.de
 */

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
            $order   = JTLMollie::API()->orders->get($payment->kID);
            $logData = '#' . $payment->kBestellung . '$' . $payment->kID;
            Mollie::JTLMollie()->doLog('Received Notification<br/><pre>' . print_r([$order, $payment], 1) . '</pre>', $logData);
            Mollie::handleOrder($order, $payment->kBestellung);
            // exit to stop execution of notify.php
            exit();
        }
    } catch (Exception $e) {
        Helper::logExc($e);
    }
}
