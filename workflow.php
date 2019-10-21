<?php

use Mollie\Api\Types\OrderStatus;
use ws_mollie\Helper;
use ws_mollie\Model\Payment;
use ws_mollie\Mollie;

try {
    $start = microtime(true);
    require_once __DIR__ . '/../../globalinclude.php';
    $oPlugin = Plugin::getPluginById("ws_mollie");
    /** @noinspection PhpIncludeInspection */
    require_once $oPlugin->cAdminmenuPfad . '../class/Helper.php';

    if (!Helper::init()) {
        return;
    }

    if (Helper::getSetting('notifyMollie') === 'W') {
        if (array_key_exists('secret', $_REQUEST) && $_REQUEST['secret'] === Helper::getSetting('workflowSecret')) {

            file_put_contents(__DIR__ . '/workflow.log', print_r([$_REQUEST, $_SERVER], 1), FILE_APPEND);
            //require_once $oPlugin->cAdminmenuPfad . '/../paymentmethod/JTLMollie.php';
            $kBestellung = array_key_exists('kBestellung', $_REQUEST) ? (int)$_REQUEST['kBestellung'] : null;
            if ($kBestellung && array_key_exists('action', $_REQUEST)) {

                $logData = '#' . $kBestellung;
                $complete = array_key_exists('komplett', $_REQUEST) && (int)$_REQUEST['komplett'];

                switch (strtolower(trim($_REQUEST['action']))) {
                    case 'storno':
                        if ($oPayment = Payment::getPayment($kBestellung)) {
                            $logData .= '$' . $oPayment->kID . '§' . $oPayment->cOrderNumber;
                            $order = Mollie::JTLMollie()::API()->orders->get($oPayment->kID);
                            if (in_array($order->status, [OrderStatus::STATUS_AUTHORIZED])) {
                                $order = JTLMollie::API()->orders->cancel($oPayment->kID);
                                Mollie::JTLMollie()->doLog("mollie//WORKFLOW: kBestellung:{$kBestellung} neuer Status: " . $order->status . ".", $logData, LOGLEVEL_NOTICE);
                            } elseif ($order->status === OrderStatus::STATUS_PAID || $order->status === OrderStatus::STATUS_COMPLETED) {
                                // TODO: Refund?
                                if ($complete) {
                                    
                                    $refund = $order->refundAll();
                                    if ($refund->status === 'failed') {
                                        Mollie::JTLMollie()->doLog("mollie//WORKFLOW: kBestellung:{$kBestellung} Refund fehlgeschlagen: " . $refund->id . ".", $logData, LOGLEVEL_ERROR);
                                        http_response_code(500);
                                        die('Refund fehlgeschlagen');
                                    } else {
                                        Mollie::JTLMollie()->doLog("mollie//WORKFLOW: kBestellung:{$kBestellung} Refund-Status: " . $refund->status . ".", $logData, LOGLEVEL_NOTICE);
                                    }

                                } else {
                                    // TODO: Partly Refund
                                }

                            }
                        } else {
                            Jtllog::writeLog("mollie//WORKFLOW: Keine mollie Zahlung zu kBestellung:{$kBestellung} gefunden.");
                            http_response_code(404);
                            die('Keine mollie Zahlung gefunden');
                        }
                        break;
                    case 'shipped':

                        if ($oPayment = Payment::getPayment($kBestellung)) {
                            $logData .= '$' . $oPayment->kID . '§' . $oPayment->cOrderNumber;
                            $order = Mollie::JTLMollie()::API()->orders->get($oPayment->kID);
                            if (in_array($order->status, [OrderStatus::STATUS_PAID, OrderStatus::STATUS_AUTHORIZED, OrderStatus::STATUS_SHIPPING])) {
                                if ($complete) {
                                    $shipment = JTLMollie::API()->shipments->createFor($order, ['lines' => []]);
                                    Mollie::JTLMollie()->doLog("mollie//WORKFLOW: kBestellung:{$kBestellung} jetzt versendet: " . $shipment->id . ".", $logData, LOGLEVEL_NOTICE);
                                } else {
                                    // TODO: Partly Shipped
                                }
                            } else {
                                Mollie::JTLMollie()->doLog("mollie//WORKFLOW: kBestellung:{$kBestellung} bereits auf Status " . $order->status . ".", $logData, LOGLEVEL_NOTICE);
                            }
                        } else {
                            Jtllog::writeLog("mollie//WORKFLOW: Keine mollie Zahlung zu kBestellung:{$kBestellung} gefunden.");
                            http_response_code(400);
                            die('Keine mollie Zahlung gefunden');
                        }

                        break;
                }
            } else {
                Jtllog::writeLog("mollie//WORKFLOW Datei aufgerufen, kBestellung oder action fehlen: " . $_SERVER['REQUEST_URI']);
                http_response_code(400);
                die('kBestellung oder action fehlen');
            }
        } else {
            Jtllog::writeLog("mollie//WORKFLOW Datei aufgerufen, Secret jedoch nicht gültig!");
            http_response_code(403);
            die('kBestellung oder action fehlen');
        }
    } else {
        Jtllog::writeLog("mollie//WORKFLOW Datei aufgerufen, Setting jedoch nicht auf Workflow!");
        http_response_code(409);
        die('Plugin nicht auf Workflows eingestellt!');
    }
} catch (Exception $e) {
    http_response_code(500);
    die($e->getMessage());
    Jtllog::writeLog("mollie//WORKFLOW//Execption: " . $e->getMessage());
}