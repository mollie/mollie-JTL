<?php

use Mollie\Api\Types\OrderStatus;
use ws_mollie\Checkout\AbstractCheckout;
use ws_mollie\Helper;

try {
    $start = microtime(true);
    require_once __DIR__ . '/../../globalinclude.php';
    $oPlugin = Plugin::getPluginById("ws_mollie");
    /** @noinspection PhpIncludeInspection */
    require_once $oPlugin->cAdminmenuPfad . '../class/Helper.php';

    if (!Helper::init()) {
        return;
    }

    require_once $oPlugin->cAdminmenuPfad . '../paymentmethod/JTLMollie.php';

    if (array_key_exists('secret', $_REQUEST) && trim($_REQUEST['secret']) !== '' && $_REQUEST['secret'] === Helper::getSetting('workflowSecret')) {

        if (defined('MOLLIE_WORKFLOW_LOG') && MOLLIE_WORKFLOW_LOG) {
            file_put_contents(__DIR__ . '/workflow.log', print_r([$_REQUEST, $_SERVER], 1), FILE_APPEND);
        }

        $kBestellung = array_key_exists('kBestellung', $_REQUEST) ? (int)$_REQUEST['kBestellung'] : null;

        $checkout = AbstractCheckout::fromBestellung($kBestellung);

        if (array_key_exists('action', $_REQUEST) && $checkout->getBestellung()->kBestellung) {

            $complete = array_key_exists('komplett', $_REQUEST) && (int)$_REQUEST['komplett'];

            switch (strtolower(trim($_REQUEST['action']))) {
                case 'storno':
                    try {
                        $result = $checkout->cancelOrRefund(true);
                        $checkout->Log("Workflow::Storno: " . $result);
                    } catch (Exception $e) {
                        $checkout->Log("Workflow::Storno: " . $e->getMessage(), LOGLEVEL_ERROR);
                    }
                    break;
                case 'shipped':

                    if (strpos($checkout->getMollie()->id, 'tr_') !== false) {
                        $checkout->Log(sprintf("Workflow: %s mit PaymnetAPI, keine Shipping notwendig.", $checkout->getBestellung()->cBestellNr));
                        return;
                    }

                    $order = $checkout->getMollie();
                    if (in_array($order->status, [OrderStatus::STATUS_PAID, OrderStatus::STATUS_AUTHORIZED, OrderStatus::STATUS_SHIPPING], true)) {
                        if ($complete) {
                            try {
                                $shipment = $checkout->API()->Client()->shipments->createFor($order, ['lines' => []]);
                                $checkout->Log(sprintf("Workflow::Shippng: Shipping für %s erstellt: %s", $checkout->getBestellung()->cBestellNr, $shipment->id));
                            } catch (Exception $e) {
                                $checkout->Log("Workflow::Shipping: " . $e->getMessage(), LOGLEVEL_ERROR);
                            }
                        }
                        // TODO: Partly Shipped
                    } else {
                        $checkout->Log(sprintf("Workflow::Shipping: %s bereits auf Status %s.", $checkout->getBestellung()->cBestellNr, $order->status));
                    }
                    break;
            }
        } else {
            $checkout->Log("Workflow:: Datei aufgerufen, kBestellung oder action fehlen: " . $_SERVER['REQUEST_URI']);
            http_response_code(400);
            die('kBestellung oder action fehlen');
        }
    } else {
        Jtllog::writeLog("mollie//WORKFLOW Datei aufgerufen, Secret jedoch nicht gültig!");
        http_response_code(403);
        die('Secret ungültig');
    }
} catch (Exception $e) {
    http_response_code(500);
    Jtllog::writeLog("mollie//WORKFLOW//Execption: " . $e->getMessage());
    die($e->getMessage());
}