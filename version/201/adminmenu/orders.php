<?php

/**
 * @copyright 2022 WebStollen GmbH
 * @link https://www.webstollen.de
 */

use Mollie\Api\Types\PaymentStatus;
use ws_mollie\Checkout\AbstractCheckout;
use ws_mollie\Checkout\OrderCheckout;
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
            case 'reminder':
                if (array_key_exists('kBestellung', $_REQUEST) && ($checkout = AbstractCheckout::fromBestellung((int)$_REQUEST['kBestellung']))) {
                    if (AbstractCheckout::sendReminder($checkout->getModel()->kID)) {
                        Helper::addAlert('Zahlungserinnerung wurde verschickt.', 'success', 'orders');
                    } else {
                        Helper::addAlert('Es ist ein Fehler aufgetreten, prüfe den Log.', 'danger', 'orders');
                    }
                } else {
                    Helper::addAlert('Bestellung konnte nicht geladen werden.', 'danger', 'orders');
                }


                break;

            case 'fetchable':
                if (array_key_exists('kBestellung', $_REQUEST) && ($checkout = AbstractCheckout::fromBestellung((int)$_REQUEST['kBestellung']))) {
                    if (AbstractCheckout::makeFetchable($checkout->getBestellung(), $checkout->getModel())) {
                        Helper::addAlert('Bestellung kann jetzt von der WAWI abgeholt werden.', 'success', 'orders');
                    } else {
                        Helper::addAlert('Es ist ein Fehler aufgetreten, prüfe den Log.', 'danger', 'orders');
                    }
                } else {
                    Helper::addAlert('Bestellung konnte nicht geladen werden.', 'danger', 'orders');
                }

                break;

            case 'export':
                try {
                    $export = [];

                    $from = new DateTime($_REQUEST['from']);
                    $to   = new DateTime($_REQUEST['to']);

                    $orders = Shop::DB()->executeQueryPrepared('SELECT * FROM xplugin_ws_mollie_payments WHERE kBestellung > 0 AND dCreatedAt >= :From AND dCreatedAt <= :To ORDER BY dCreatedAt', [
                        ':From' => $from->format('Y-m-d'),
                        ':To'   => $to->format('Y-m-d'),
                    ], 2);


                    header('Content-Type: application/csv');
                    header('Content-Disposition: attachment; filename=mollie-' . $from->format('Ymd') . '-' . $to->format('Ymd') . '.csv');
                    header('Pragma: no-cache');

                    $out = fopen('php://output', 'wb');


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
                        $order    = new ws_mollie\Model\Payment($order);
                        $checkout = AbstractCheckout::fromModel($order);

                        $tmp = [
                            'kBestellung'          => $order->kBestellung,
                            'cOrderId'             => $order->kID,
                            'cStatus'              => $checkout->getMollie() ? $checkout->getMollie()->status : $order->cStatus,
                            'cBestellNr'           => $checkout->getBestellung() ? $checkout->getBestellung()->cBestellNr : $order->cOrderNumber,
                            'nStatus'              => $checkout->getBestellung() ? $checkout->getBestellung()->cStatus : 0,
                            'cMode'                => $order->cMode,
                            'cOriginalOrderNumber' => $checkout->getMollie() && isset($checkout->getMollie()->metadata->originalOrderNumber) ? $checkout->getMollie()->metadata->originalOrderNumber : '',
                            'cCurrency'            => $order->cCurrency,
                            'fAmount'              => $order->fAmount,
                            'cMethod'              => $order->cMethod,
                            'cPaymentId'           => $order->cTransactionId,
                            'dCreated'             => $order->dCreatedAt,
                        ];

                        try {
                            if ($checkout->getMollie() && $checkout->getMollie()->resource === 'order') {
                                foreach ($checkout->getMollie()->payments() as $payment) {
                                    if ($payment->status === PaymentStatus::STATUS_PAID) {
                                        $tmp['cPaymentId'] = $payment->id;
                                    }
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
                try {
                    if (!array_key_exists('id', $_REQUEST)) {
                        throw new InvalidArgumentException('Keine ID angegeben!', 'danger', 'orders');
                    }
                    $checkout = AbstractCheckout::fromID($_REQUEST['id']);
                    if ($refund = $checkout::refund($checkout)) {
                        Helper::addAlert(sprintf('Bestellung wurde zurückerstattet (%s).', $refund->id), 'success', 'orders');
                    }
                    goto order;
                } catch (InvalidArgumentException $e) {
                    Helper::addAlert('Fehler: ' . $e->getMessage(), 'danger', 'orders');
                } catch (Exception $e) {
                    Helper::addAlert('Fehler: ' . $e->getMessage(), 'danger', 'orders');
                    goto order;
                }

                break;

            case 'cancel':
                try {
                    if (!array_key_exists('id', $_REQUEST)) {
                        throw new InvalidArgumentException('Keine ID angeben!');
                    }
                    $checkout = AbstractCheckout::fromID($_REQUEST['id']);
                    if ($checkout::cancel($checkout)) {
                        Helper::addAlert('Bestellung wurde abgebrochen.', 'success', 'orders');
                    }
                    goto order;
                } catch (InvalidArgumentException $e) {
                    Helper::addAlert('Fehler: ' . $e->getMessage(), 'danger', 'orders');
                } catch (Exception $e) {
                    Helper::addAlert('Fehler: ' . $e->getMessage(), 'danger', 'orders');
                    goto order;
                }

                break;

            case 'capture':
                try {
                    if (!array_key_exists('id', $_REQUEST)) {
                        throw new InvalidArgumentException('Keine ID angeben!');
                    }
                    $checkout = AbstractCheckout::fromID($_REQUEST['id']);
                    if ($shipmentId = OrderCheckout::capture($checkout)) {
                        Helper::addAlert(sprintf('Zahlung erfolgreich erfasst/versandt (%s).', $shipmentId), 'success', 'orders');
                    }
                    goto order;
                } catch (InvalidArgumentException $e) {
                    Helper::addAlert('Fehler: ' . $e->getMessage(), 'danger', 'orders');
                } catch (Exception $e) {
                    Helper::addAlert('Fehler: ' . $e->getMessage(), 'danger', 'orders');
                    goto order;
                }

                break;

            case 'order':
                order :
                try {
                    if (!array_key_exists('id', $_REQUEST)) {
                        throw new InvalidArgumentException('Keine ID angeben!');
                    }

                    $checkout = AbstractCheckout::fromID($_REQUEST['id']);

                    if ($checkout instanceof OrderCheckout) {
                        Shop::Smarty()->assign('shipments', $checkout->getShipments());
                    }

                    Shop::Smarty()->assign('payment', $checkout->getModel())
                        ->assign('oBestellung', $checkout->getBestellung())
                        ->assign('order', $checkout->getMollie())
                        ->assign('checkout', $checkout)
                        ->assign('logs', $checkout->getLogs());
                    Shop::Smarty()->display($oPlugin->cAdminmenuPfad . '/tpl/order.tpl');

                    return;
                } catch (Exception $e) {
                    Helper::addAlert('Fehler: ' . $e->getMessage(), 'danger', 'orders');
                }

                break;
        }
    }

    Mollie::fixZahlungsarten();

    $checkouts = [];
    $payments  = Shop::DB()->executeQueryPrepared('SELECT * FROM xplugin_ws_mollie_payments WHERE kBestellung IS NOT NULL ORDER BY dCreatedAt DESC LIMIT 1000;', [], 2);
    foreach ($payments as $i => $payment) {
        $payment                          = new Payment($payment);
        $checkouts[$payment->kBestellung] = AbstractCheckout::fromModel($payment, false);
    }

    Shop::Smarty()->assign('payments', $payments)
        ->assign('checkouts', $checkouts)
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
