<?php

/**
 * @copyright 2022 WebStollen GmbH
 * @link https://www.webstollen.de
 */

use Mollie\Api\Types\OrderStatus;
use ws_mollie\Helper;
use ws_mollie\Model\Payment;
use ws_mollie\Mollie;

require_once __DIR__ . '/../class/Helper.php';

try {
    if (!Helper::init()) {
        echo 'Kein gültige Lizenz?';

        return;
    }
    require_once __DIR__ . '/../paymentmethod/JTLMollie.php';
    global $oPlugin;

    if (array_key_exists('action', $_REQUEST)) {
        switch ($_REQUEST['action']) {
            case 'export':
                try {
                    $export = [];

                    $from = new DateTime($_REQUEST['from']);
                    $to   = new DateTime($_REQUEST['to']);

                    $orders = Shop::DB()->executeQueryPrepared('SELECT * FROM xplugin_ws_mollie_payments WHERE kBestellung > 0 AND dCreatedAt >= :From AND dCreatedAt <= :To ORDER BY dCreatedAt', [
                        ':From' => $from->format('Y-m-d'),
                        ':To'   => $to->format('Y-m-d'),
                    ], 2);


                    $api = JTLMollie::API();

                    header('Content-Type: application/csv');
                    header('Content-Disposition: attachment; filename=mollie-' . $from->format('Ymd') . '-' . $to->format('Ymd') . '.csv');
                    header('Pragma: no-cache');

                    $out = fopen('php://output', 'w');


                    fputcsv($out, [
                        'kBestellung',
                        'OrderID',
                        'Status (mollie)',
                        'BestellNr',
                        'Status (JTL)',
                        'Mode',
                        'OriginalOrderNumber',
                        'Currency',
                        'Amount',
                        'Method',
                        'PaymentID',
                        'Created'
                    ]);


                    foreach ($orders as $order) {
                        $tbestellung = Shop::DB()->executeQueryPrepared('SELECT cBestellNr, cStatus FROM tbestellung WHERE kBestellung = :kBestellung', [':kBestellung' => $order->kBestellung], 1);

                        $tmp = [
                            'kBestellung'          => $order->kBestellung,
                            'cOrderId'             => $order->kID,
                            'cStatus'              => $order->cStatus,
                            'cBestellNr'           => $tbestellung ? $tbestellung->cBestellNr : $order->cOrderNumber,
                            'nStatus'              => $tbestellung ? $tbestellung->cStatus : 0,
                            'cMode'                => $order->cMode,
                            'cOriginalOrderNumber' => '',
                            'cCurrency'            => $order->cCurrency,
                            'fAmount'              => $order->fAmount,
                            'cMethod'              => $order->cMethod,
                            'cPaymentId'           => '',
                            'dCreated'             => $order->dCreatedAt,
                        ];

                        try {
                            $oOrder                      = $api->orders->get($order->kID, ['embed' => 'payments']);
                            $tmp['cStatus']              = $oOrder->status;
                            $tmp['cOriginalOrderNumber'] = isset($oOrder->metadata->originalOrderNumber) ? $oOrder->metadata->originalOrderNumber : '';
                            foreach ($oOrder->payments() as $payment) {
                                if ($payment->status === \Mollie\Api\Types\PaymentStatus::STATUS_PAID) {
                                    $tmp['cPaymentId'] = $payment->id;
                                }
                            }
                        } catch (Exception $e) {
                        }
                        fputcsv($out, $tmp);

                        $export[] = $tmp;
                    }

                    fclose($out);
                    exit();
                } catch (Exception $e) {
                    Helper::addAlert('Fehler:' . $e->getMessage(), 'danger', 'orders');
                }

                break;

            case 'refund':
                if (!array_key_exists('id', $_REQUEST)) {
                    Helper::addAlert('Keine ID angegeben!', 'danger', 'orders');

                    break;
                }
                $payment = Payment::getPaymentMollie($_REQUEST['id']);
                if (!$payment) {
                    Helper::addAlert('Order nicht gefunden!', 'danger', 'orders');

                    break;
                }

                $order = JTLMollie::API()->orders->get($_REQUEST['id']);
                if ($order->status === OrderStatus::STATUS_CANCELED) {
                    Helper::addAlert('Bestellung bereits storniert', 'danger', 'orders');

                    break;
                }
                $refund = JTLMollie::API()->orderRefunds->createFor($order, ['lines' => []]);
                Mollie::JTLMollie()->doLog('Order refunded: <br/><pre>' . print_r($refund, 1) . '</pre>', '$' . $payment->kID . '#' . $payment->kBestellung . '§' . $payment->cOrderNumber, LOGLEVEL_NOTICE);

                goto order;

            case 'cancel':
                if (!array_key_exists('id', $_REQUEST)) {
                    Helper::addAlert('Keine ID angeben!', 'danger', 'orders');

                    break;
                }
                $payment = Payment::getPaymentMollie($_REQUEST['id']);
                if (!$payment) {
                    Helper::addAlert('Order nicht gefunden!', 'danger', 'orders');

                    break;
                }
                $order = JTLMollie::API()->orders->get($_REQUEST['id']);
                if ($order->status == OrderStatus::STATUS_CANCELED) {
                    Helper::addAlert('Bestellung bereits storniert', 'danger', 'orders');

                    break;
                }
                $cancel = JTLMollie::API()->orders->cancel($order->id);
                Mollie::JTLMollie()->doLog('Order canceled: <br/><pre>' . print_r($cancel, 1) . '</pre>', '$' . $payment->kID . '#' . $payment->kBestellung . '§' . $payment->cOrderNumber, LOGLEVEL_NOTICE);
                goto order;

            case 'capture':
                if (!array_key_exists('id', $_REQUEST)) {
                    Helper::addAlert('Keine ID angeben!', 'danger', 'orders');

                    break;
                }
                $payment = Payment::getPaymentMollie($_REQUEST['id']);
                if (!$payment) {
                    Helper::addAlert('Order nicht gefunden!', 'danger', 'orders');

                    break;
                }
                $order = JTLMollie::API()->orders->get($_REQUEST['id']);
                if ($order->status !== OrderStatus::STATUS_AUTHORIZED && $order->status !== OrderStatus::STATUS_SHIPPING) {
                    Helper::addAlert('Nur autorisierte Zahlungen können erfasst werden!', 'danger', 'orders');

                    break;
                }

                $oBestellung = new Bestellung($payment->kBestellung, true);
                if (!$oBestellung->kBestellung) {
                    Helper::addAlert('Bestellung konnte nicht geladen werden!', 'danger', 'orders');

                    break;
                }

                $logData = '#' . $payment->kBestellung . '$' . $payment->kID . '§' . $oBestellung->cBestellNr;

                $options = ['lines' => []];
                if ($oBestellung->cTracking) {
                    $tracking            = new stdClass();
                    $tracking->carrier   = utf8_encode($oBestellung->cVersandartName);
                    $tracking->url       = utf8_encode($oBestellung->cTrackingURL);
                    $tracking->code      = utf8_encode($oBestellung->cTracking);
                    $options['tracking'] = $tracking;
                }

                // CAPTURE ALL
                $shipment = JTLMollie::API()->shipments->createFor($order, $options);
                Helper::addAlert('Zahlung wurde erfolgreich erfasst!', 'success', 'orders');
                Mollie::JTLMollie()->doLog('Shipment created<br/><pre>' . print_r(['options' => $options, 'shipment' => $shipment], 1) . '</pre>', $logData);
                goto order;

            case 'order':
                order :
                if (!array_key_exists('id', $_REQUEST)) {
                    Helper::addAlert('Keine ID angeben!', 'danger', 'orders');

                    break;
                }

                $order   = JTLMollie::API()->orders->get($_REQUEST['id'], ['embed' => 'payments,refunds']);
                $payment = Payment::getPaymentMollie($_REQUEST['id']);
                if ($payment) {
                    $oBestellung = new Bestellung($payment->kBestellung, false);
                    //\ws_mollie\Model\Payment::updateFromPayment($order, $oBestellung->kBestellung);
                    if ($oBestellung->kBestellung && $oBestellung->cBestellNr !== $payment->cOrderNumber) {
                        Shop::DB()->executeQueryPrepared('UPDATE xplugin_ws_mollie_payments SET cOrderNumber = :cBestellNr WHERE kID = :kID', [
                            ':cBestellNr' => $oBestellung->cBestellNr,
                            ':kID'        => $payment->kID,
                        ], 3);
                    }
                }
                $logs = Shop::DB()->executeQueryPrepared('SELECT * FROM tzahlungslog WHERE cLogData LIKE :kBestellung OR cLogData LIKE :cBestellNr OR cLogData LIKE :MollieID ORDER BY dDatum DESC, cLog DESC', [
                    ':kBestellung' => '%#' . ($payment->kBestellung ?: '##') . '%',
                    ':cBestellNr'  => '%§' . ($payment->cOrderNumber ?: '§§') . '%',
                    ':MollieID'    => '%$' . ($payment->kID ?: '$$') . '%',
                ], 2);

                Shop::Smarty()->assign('payment', $payment)
                    ->assign('oBestellung', $oBestellung)
                    ->assign('order', $order)
                    ->assign('logs', $logs);
                Shop::Smarty()->display($oPlugin->cAdminmenuPfad . '/tpl/order.tpl');

                return;
        }
    }


    Mollie::fixZahlungsarten();


    $payments = Shop::DB()->executeQueryPrepared("SELECT * FROM xplugin_ws_mollie_payments WHERE kBestellung IS NOT NULL AND cStatus != 'created' ORDER BY dCreatedAt DESC LIMIT 1000;", [], 2);
    foreach ($payments as $i => $payment) {
        $payments[$i]->oBestellung = new Bestellung($payment->kBestellung, false);
    }

    Shop::Smarty()->assign('payments', $payments)
        ->assign('admRoot', str_replace('http:', '', $oPlugin->cAdminmenuPfadURL))
        ->assign('hasAPIKey', trim(Helper::getSetting('api_key')) !== '');

    Shop::Smarty()->display($oPlugin->cAdminmenuPfad . '/tpl/orders.tpl');
} catch (Exception $e) {
    echo "<div class='alert alert-danger'>" .
        "{$e->getMessage()}<br/>" .
        "<blockquote>{$e->getFile()}:{$e->getLine()}<br/><pre>{$e->getTraceAsString()}</pre></blockquote>" .
        '</div>';
    Helper::logExc($e);
}
