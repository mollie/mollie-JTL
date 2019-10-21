<?php

use ws_mollie\Helper;
use ws_mollie\Model\Payment;
use ws_mollie\Mollie;

try {
    require_once __DIR__ . '/../class/Helper.php';
    Helper::init();

    // NUR BEI HOOK SETTING:
    if (Helper::getSetting('notifyMollie') === 'H') {

        $status = (int)$args_arr['status'];
        /** @var Bestellung $oBestellung */
        $oBestellung = $args_arr['oBestellung'];
        // Order got paid with mollie:
        if ($oBestellung->kBestellung && $payment = Payment::getPayment($oBestellung->kBestellung)) {
            $logData = '#' . $payment->kBestellung . '$' . $payment->kID . "§" . $oBestellung->cBestellNr;
            Mollie::JTLMollie()->doLog("WAWI Abgleich: HOOK_BESTELLUNGEN_XML_BESTELLSTATUS<pre>" . print_r($args_arr, 1) . "</pre>", $logData, LOGLEVEL_DEBUG);
            try {
                $order = JTLMollie::API()->orders->get($payment->kID);
                //$order->orderNumber = $oBestellung->cBestellNr;
                Mollie::handleOrder($order, $oBestellung->kBestellung);
                if ($order->isCreated() || $order->isPaid() || $order->isAuthorized() || $order->isShipping() || $order->isPending()) {
                    Mollie::JTLMollie()->doLog("Create Shippment: <br/><pre>" . print_r($args_arr, 1) . '</pre>', $logData, LOGLEVEL_DEBUG);
                    $options = Mollie::getShipmentOptions($order, $oBestellung->kBestellung, $status);
                    if ($options && array_key_exists('lines', $options) && is_array($options['lines'])) {
                        require_once __DIR__ . '/../paymentmethod/JTLMollie.php';
                        $shipment = JTLMollie::API()->shipments->createFor($order, $options);
                        Mollie::JTLMollie()->doLog('Shipment created<br/><pre>' . print_r(['options' => $options, 'shipment' => $shipment], 1) . '</pre>', $logData, LOGLEVEL_NOTICE);
                    } elseif ((int)$status !== BESTELLUNG_STATUS_BEZAHLT) {
                        Mollie::JTLMollie()->doLog('181_sync: options don\'t contain lines<br><pre>' . print_r([$order, $options], 1) . '</pre>', $logData, LOGLEVEL_ERROR);
                    }
                }
            } catch (Exception $e) {
                Mollie::JTLMollie()->doLog('Fehler: ' . $e->getMessage() . '<br><pre>' . print_r($e->getTrace(), 1) . '</pre>', $logData, LOGLEVEL_ERROR);
            }
        }

    }
} catch (Exception $e) {
    Helper::logExc($e);
}
