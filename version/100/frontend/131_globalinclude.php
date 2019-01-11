<?php

// TODO: Some more logging

if (strpos($_SERVER['PHP_SELF'], 'bestellabschluss') === false) {
    return;
}

require_once __DIR__ . '/../class/Helper.php';
try {
    \ws_mollie\Helper::init();

    ob_start();
    if (array_key_exists('mollie', $_REQUEST)) {
        $payment = \Shop::DB()->executeQueryPrepared("SELECT * FROM " . \ws_mollie\Model\Payment::TABLE . " WHERE cHash = :cHash", [':cHash' => $_REQUEST['mollie']], 1);
        if ((int)$payment->kBestellung) {

            \ws_mollie\Mollie::getOrderCompletedRedirect($payment->kBestellung, true);

        } elseif ($payment) {

            $mollie = new \Mollie\Api\MollieApiClient();
            $mollie->setApiKey(\ws_mollie\Helper::getSetting("api_key"));

            $order = $mollie->orders->get($payment->kID);

            if ($order) {
                $session = Session::getInstance();
                require_once PFAD_ROOT . 'includes/bestellabschluss_inc.php';
                require_once PFAD_ROOT . 'includes/mailTools.php';

                $oBestellung = fakeBestellung();
                $oBestellung = finalisiereBestellung();

                $order->orderNumber = $oBestellung->cBestellNr;
                \ws_mollie\Model\Payment::updateFromPayment($order, $oBestellung->kBestellung);
                \ws_mollie\Mollie::getOrderCompletedRedirect($oBestellung->kBestellung, true);

            }
        }
    }
    ob_end_flush();
} catch (Exception $e) {
    \ws_mollie\Helper::logExc($e);
}