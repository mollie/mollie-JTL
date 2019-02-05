<?php
try {
    require_once __DIR__ . '/../class/Helper.php';
    \ws_mollie\Helper::init();
    $status = (int)$args_arr['status'];
    /** @var Bestellung $oBestellung */
    $oBestellung = $args_arr['oBestellung'];
    // Order got paid with mollie:
    if ($oBestellung->kBestellung && $payment = \ws_mollie\Model\Payment::getPayment($oBestellung->kBestellung)) {
        $logData = '#' . $payment->kBestellung . '$' . $payment->kID . "§" . $oBestellung->cBestellNr;
        \ws_mollie\Mollie::JTLMollie()->doLog("WAWI Abgleich: HOOK_BESTELLUNGEN_XML_BESTELLSTATUS", $logData);
        try {
            $order = JTLMollie::API()->orders->get($payment->kID);
            $order->orderNumber = $oBestellung->cBestellNr;
            \ws_mollie\Mollie::handleOrder($order, $oBestellung->kBestellung);
            if ($order->isCreated() || $order->isPaid() || $order->isAuthorized() || $order->isShipping() || $order->isPending()) {
                \ws_mollie\Mollie::JTLMollie()->doLog("Create Shippment: <br/><pre>" . print_r($args_arr, 1) . '</pre>', $logData, LOGLEVEL_DEBUG);
                $options = \ws_mollie\Mollie::getShipmentOptions($order, $oBestellung->kBestellung, $status);
                if ($options && array_key_exists('lines', $options) && is_array($options['lines'])) {
                    require_once __DIR__ . '/../paymentmethod/JTLMollie.php';
                    $shipment = JTLMollie::API()->shipments->createFor($order, $options);
                    \ws_mollie\Mollie::JTLMollie()->doLog('Shipment created<br/><pre>' . print_r(['options' => $options, 'shipment' => $shipment], 1) . '</pre>', $logData, LOGLEVEL_NOTICE);
                }else{
	                \ws_mollie\Mollie::JTLMollie()->doLog('181_sync: options don\'t contain lines<br><pre>' . print_r([$order, $options], 1) . '</pre>', $logData, LOGLEVEL_ERROR);   
                }
            }
        } catch (Exception $e) {
            \ws_mollie\Mollie::JTLMollie()->doLog('Fehler: ' . $e->getMessage() . '<br><pre>' . print_r($e->getTrace(), 1) . '</pre>', $logData, LOGLEVEL_ERROR);
        }
    }
} catch (Exception $e) {
    \ws_mollie\Helper::logExc($e);
}