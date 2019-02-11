<?php

if (strpos($_SERVER['PHP_SELF'], 'bestellabschluss') === false) {
    return;
}
require_once __DIR__ . '/../class/Helper.php';
try {
    \ws_mollie\Helper::init();
    // suppress any output, for redirect
    ob_start();
    if (array_key_exists('mollie', $_REQUEST)) {
        $payment = \Shop::DB()->executeQueryPrepared("SELECT * FROM " . \ws_mollie\Model\Payment::TABLE . " WHERE cHash = :cHash", [':cHash' => $_REQUEST['mollie']], 1);
        // Bestellung finalized, redirect to status/completion page
        if ((int)$payment->kBestellung) {
            $logData = '$' . $payment->kID . '#' . $payment->kBestellung . "§" . $payment->cOrderNumber;
            \ws_mollie\Mollie::JTLMollie()->doLog('Bestellung finalized => redirect abschluss/status', $logData);
            $order = JTLMollie::API()->orders->get($payment->kID, ['embed' => 'payments']);
            \ws_mollie\Mollie::handleOrder($order, $payment->kBestellung);
            \ws_mollie\Mollie::getOrderCompletedRedirect($payment->kBestellung, true);
        } elseif ($payment) { // payment, but no order => finalize it
            require_once __DIR__ . '/../paymentmethod/JTLMollie.php';
            $order = JTLMollie::API()->orders->get($payment->kID, ['embed' => 'payments']);
            $logData = '$' . $order->id . '#' . $payment->kBestellung . "§" . $payment->cOrderNumber;

            // GET NEWEST PAYMENT:
            /** @var \Mollie\Api\Resources\Paymentt $_payment */
            $_payment = null;
            if ($order->payments()) {
                /** @var \Mollie\Api\Resources\Payment $p */
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
                if ($_payment && !in_array($_payment->status, [\Mollie\Api\Types\PaymentStatus::STATUS_EXPIRED, \Mollie\Api\Types\PaymentStatus::STATUS_CANCELED, \Mollie\Api\Types\PaymentStatus::STATUS_OPEN, \Mollie\Api\Types\PaymentStatus::STATUS_FAILED, \Mollie\Api\Types\PaymentStatus::STATUS_CANCELED])) {
                    \ws_mollie\Mollie::JTLMollie()->doLog('Bestellung open => finalize', $logData);
                    $session = Session::getInstance();
                    /** @noinspection PhpIncludeInspection */
                    require_once PFAD_ROOT . 'includes/bestellabschluss_inc.php';
                    /** @noinspection PhpIncludeInspection */
                    require_once PFAD_ROOT . 'includes/mailTools.php';
                    $oBestellung = fakeBestellung();
                    $oBestellung = finalisiereBestellung();
                    \ws_mollie\Mollie::JTLMollie()->doLog('Bestellung finalized => redirect<br/><pre>' . print_r($order, 1) . '</pre>', $logData, LOGLEVEL_DEBUG);
                    \ws_mollie\Mollie::handleOrder($order, $oBestellung->kBestellung);
                    \ws_mollie\Mollie::getOrderCompletedRedirect($oBestellung->kBestellung, true);
                } else {
                    \ws_mollie\Mollie::JTLMollie()->doLog('Invalid Payment<br/><pre>' . print_r($payment, 1) . '</pre>', $logData, LOGLEVEL_ERROR);
                }
            } else {
                \ws_mollie\Mollie::JTLMollie()->doLog('Invalid Order<br/><pre>' . print_r($order, 1) . '</pre>', $logData, LOGLEVEL_ERROR);
            }
        }
    }
    ob_end_flush();
} catch (Exception $e) {
    \ws_mollie\Helper::logExc($e);
}
