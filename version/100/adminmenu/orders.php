<?php

require_once __DIR__ . '/../class/Helper.php';
try {
    if (!\ws_mollie\Helper::init()) {
        echo "Kein g�ltige Lizenz?";
        return;
    }
    require_once __DIR__ . '/../paymentmethod/JTLMollie.php';
    global $oPlugin;
    $ordersMsgs = [];
    if (array_key_exists('action', $_REQUEST)) {
        switch ($_REQUEST['action']) {
            case 'refund':
                if (!array_key_exists('id', $_REQUEST)) {
                    $ordersMsgs[] = (object)['type' => 'danger', 'text' => 'Keine ID angeben!'];
                    break;
                }
                $payment = \ws_mollie\Model\Payment::getPaymentMollie($_REQUEST['id']);
                if (!$payment) {
                    $ordersMsgs[] = (object)['type' => 'danger', 'text' => 'Order nicht gefunden!'];
                    break;
                }

                $order = JTLMollie::API()->orders->get($_REQUEST['id']);
                if ($order->status == \Mollie\Api\Types\OrderStatus::STATUS_CANCELED) {
                    $ordersMsgs[] = (object)['type' => 'danger', 'text' => 'Bestellung bereits storniert'];
                    break;
                }
                $refund = JTLMollie::API()->orderRefunds->createFor($order, ['lines' => []]);
                \ws_mollie\Mollie::JTLMollie()->doLog("Order refunded: <br/><pre>" . print_r($refund, 1) . "</pre>", '$' . $payment->kID . '#' . $payment->kBestellung . '�' . $payment->cOrderNumber, LOGLEVEL_NOTICE);

                goto order;
                break;

            case 'cancel';
                if (!array_key_exists('id', $_REQUEST)) {
                    $ordersMsgs[] = (object)['type' => 'danger', 'text' => 'Keine ID angeben!'];
                    break;
                }
                $payment = \ws_mollie\Model\Payment::getPaymentMollie($_REQUEST['id']);
                if (!$payment) {
                    $ordersMsgs[] = (object)['type' => 'danger', 'text' => 'Order nicht gefunden!'];
                    break;
                }
                $order = JTLMollie::API()->orders->get($_REQUEST['id']);
                if ($order->status == \Mollie\Api\Types\OrderStatus::STATUS_CANCELED) {
                    $ordersMsgs[] = (object)['type' => 'danger', 'text' => 'Bestellung bereits storniert'];
                    break;
                }
                $cancel = JTLMollie::API()->orders->cancel($order->id);
                \ws_mollie\Mollie::JTLMollie()->doLog("Order canceled: <br/><pre>" . print_r($cancel, 1) . "</pre>", '$' . $payment->kID . '#' . $payment->kBestellung . '�' . $payment->cOrderNumber, LOGLEVEL_NOTICE);
                goto order;
                break;

            case 'capture':
                if (!array_key_exists('id', $_REQUEST)) {
                    $ordersMsgs[] = (object)['type' => 'danger', 'text' => 'Keine ID angeben!'];
                    break;
                }
                $payment = \ws_mollie\Model\Payment::getPaymentMollie($_REQUEST['id']);
                if (!$payment) {
                    $ordersMsgs[] = (object)['type' => 'danger', 'text' => 'Order nicht gefunden!'];
                    break;
                }
                $order = JTLMollie::API()->orders->get($_REQUEST['id']);
                if ($order->status !== \Mollie\Api\Types\OrderStatus::STATUS_AUTHORIZED && $order->status !== \Mollie\Api\Types\OrderStatus::STATUS_SHIPPING) {
                    $ordersMsgs[] = (object)['type' => 'danger', 'text' => 'Nur autorisierte Zahlungen k�nnen erfasst werden!'];
                    break;
                }

                $oBestellung = new Bestellung($payment->kBestellung, true);
                if (!$oBestellung->kBestellung) {
                    $ordersMsgs[] = (object)['type' => 'danger', 'text' => 'Bestellung konnte nicht geladen werden!'];
                    break;
                }

                $logData = '#' . $payment->kBestellung . '$' . $payment->kID . "�" . $oBestellung->cBestellNr;

                $options = ['lines' => []];
                if ($oBestellung->cTracking) {
                    $tracking = new stdClass();
                    $tracking->carrier = $oBestellung->cVersandartName;
                    $tracking->url = $oBestellung->cTrackingURL;
                    $tracking->code = $oBestellung->cTracking;
                    $options['tracking'] = $tracking;
                }

                // CAPTURE ALL
                $shipment = JTLMollie::API()->shipments->createFor($order, $options);
                $ordersMsgs[] = (object)['type' => 'success', 'text' => 'Zahlung wurde erfolgreich erfasst!'];
                \ws_mollie\Mollie::JTLMollie()->doLog('Shipment created<br/><pre>' . print_r(['options' => $options, 'shipment' => $shipment], 1) . '</pre>', $logData);
                goto order;

            case 'order':
                order:
                if (!array_key_exists('id', $_REQUEST)) {
                    $ordersMsgs[] = (object)['type' => 'danger', 'text' => 'Keine ID angeben!'];
                    break;
                }

                $order = JTLMollie::API()->orders->get($_REQUEST['id']);
                $payment = \ws_mollie\Model\Payment::getPaymentMollie($_REQUEST['id']);
                if ($payment) {
                    $oBestellung = new Bestellung($payment->kBestellung, false);
                    //\ws_mollie\Model\Payment::updateFromPayment($order, $oBestellung->kBestellung);
                    if ($oBestellung->kBestellung && $oBestellung->cBestellNr !== $payment->cOrderNumber) {
                        Shop::DB()->executeQueryPrepared("UPDATE xplugin_ws_mollie_payments SET cOrderNumber = :cBestellNr WHERE kID = :kID", [
                            ':cBestellNr' => $oBestellung->cBestellNr,
                            ':kID' => $payment->kID,
                        ], 3);
                    }
                }

                $logs = Shop::DB()->executeQueryPrepared("SELECT * FROM tzahlungslog WHERE cLogData LIKE :kBestellung OR cLogData LIKE :cBestellNr OR cLogData LIKE :MollieID ORDER BY dDatum DESC", [
                    ':kBestellung' => '%#' . ($payment->kBestellung ?: '##') . '%',
                    ':cBestellNr' => '%�' . ($payment->cOrderNumber ?: '��') . '%',
                    ':MollieID' => '%$' . ($payment->kID ?: '$$') . '%',
                ], 2);

                Shop::Smarty()->assign('payment', $payment)
                    ->assign('oBestellung', $oBestellung)
                    ->assign('order', $order)
                    ->assign('logs', $logs)
                    ->assign('ordersMsgs', $ordersMsgs);
                Shop::Smarty()->display($oPlugin->cAdminmenuPfad . '/tpl/order.tpl');
                return;
        }
    }


    $payments = Shop::DB()->executeQueryPrepared("SELECT * FROM xplugin_ws_mollie_payments WHERE kBestellung IS NOT NULL AND cStatus != 'created'", [], 2);
    foreach ($payments as $i => $payment) {
        $payments[$i]->oBestellung = new Bestellung($payment->kBestellung, false);
    }

    Shop::Smarty()->assign('payments', $payments)
        ->assign('ordersMsgs', $ordersMsgs);

    Shop::Smarty()->display($oPlugin->cAdminmenuPfad . '/tpl/orders.tpl');

} catch (Exception $e) {
    echo "<div class='alert alert-danger'>" .
        "{$e->getMessage()}<br/>" .
        "<blockquote>{$e->getFile()}:{$e->getLine()}<br/><pre>{$e->getTraceAsString()}</pre></blockquote>" .
        "</div>";
    \ws_mollie\Helper::logExc($e);
}