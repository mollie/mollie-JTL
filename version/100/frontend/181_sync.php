<?php
try {
    require_once __DIR__ . '/../class/Helper.php';
    \ws_mollie\Helper::init();

    $status = (int)$args_arr['status'];
    /** @var Bestellung $oBestellung */
    $oBestellung = new Bestellung($args_arr['oBestellung']->kBestellung, false);

    if ($oBestellung->kBestellung && $payment = \ws_mollie\Model\Payment::getPayment($oBestellung->kBestellung)) {

        $logData = '#' . $payment->kBestellung . '$' . $payment->kID . "§" . $oBestellung->cBestellNr;
        \ws_mollie\Mollie::JTLMollie()->doLog("WAWI Abgleich: HOOK_BESTELLUNGEN_XML_BESTELLSTATUS", $logData);
        try {

            $mollie = new \Mollie\Api\MollieApiClient();
            $mollie->setApiKey(\ws_mollie\Helper::getSetting("api_key"));
            $order = $mollie->orders->get($payment->kID);

            if ($order->status === \Mollie\Api\Types\OrderStatus::STATUS_AUTHORIZED || \Mollie\Api\Types\OrderStatus::STATUS_PAID) {
                \ws_mollie\Mollie::JTLMollie()->doLog("Create Shippment: <br/><pre>" . print_r([$order, $args_arr], 1) . '</pre>', $logData, LOGLEVEL_DEBUG);


                if ($oBestellung->cTracking) {
                    $tracking = new stdClass();
                    $tracking->carrier = $oBestellung->cVersandartName;
                    $tracking->url = $oBestellung->cTrackingURL;
                    $tracking->code = $oBestellung->cTracking;
                    $options['tracking'] = $tracking;
                }

                if ($status === BESTELLUNG_STATUS_VERSANDT) {
                    $options = ['lines' => []];
                    $shipment = $mollie->shipments->createFor($order, $options);
                    \ws_mollie\Mollie::JTLMollie()->doLog('Shipment created<br/><pre>' . print_r(['options' => $options, 'shipment' => $shipment], 1) . '</pre>', $logData);
                } elseif ($status === BESTELLUNG_STATUS_TEILVERSANDT) {
                    $lines = [];
                    /**
                     * @var int $i
                     * @var \Mollie\Api\Resources\OrderLine $line
                     */
                    foreach ($order->lines as $i => $line) {
                        if (($quantity = \ws_mollie\Mollie::getBestellPosSent($line->sku, $oBestellung)) !== false) {
                            $lines[] = (object)[
                                'id' => $line->id,
                                'quantity' => $quantity,
                                'amount' => (object)[
                                    'currency' => $line->totalAmount->currency,
                                    'value' => number_format($quantity * $line->unitPrice->value, 2),
                                ],
                            ];
                        }
                    }
                    if (count($lines)) {
                        $options = ['lines' => $lines];
                    } else {
                        throw new Exception("No Lines to ship found.");
                    }
                    $shipment = $mollie->shipments->createFor($order, $options);
                    \ws_mollie\Mollie::JTLMollie()->doLog('Partially Shipment created<br/><pre>' . print_r(['options' => $options, 'shipment' => $shipment], 1) . '</pre>', $logData);
                }
            }

        } catch (Exception $e) {
            \ws_mollie\Mollie::JTLMollie()->doLog('Fehler: ' . $e->getMessage() . '<br><pre>' . print_r($e->getTrace(), 1) . '</pre>', $logData, LOGLEVEL_ERROR);
        }
    }
} catch (Exception $e) {
    \ws_mollie\Helper::logExc($e);
}