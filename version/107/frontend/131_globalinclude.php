<?php

use Mollie\Api\Types\OrderStatus;
use Mollie\Api\Types\PaymentStatus;
use ws_mollie\Helper;
use ws_mollie\Mollie;

if (strpos($_SERVER['PHP_SELF'], 'bestellabschluss') === false) {
    return;
}
require_once __DIR__ . '/../class/Helper.php';


try {
    Helper::init();
    ob_start();

    if (array_key_exists('mollie', $_REQUEST)) {

        require_once __DIR__ . '/../paymentmethod/JTLMollie.php';

        if ($oZahlungSession = JTLMollie::getZahlungSession($_REQUEST['mollie'])) {

            $logData = '$' . $oZahlungSession->cNotifyID;

            if (!(int)$oZahlungSession->kBestellung && $oZahlungSession->cNotifyID) {
                // Bestellung noch nicht finalisiert
                $mOrder = JTLMollie::API()->orders->get($oZahlungSession->cNotifyID, ['embed' => 'payments']);
                if ($mOrder && $mOrder->id === $oZahlungSession->cNotifyID) {

                    if (!in_array($mOrder->status, [OrderStatus::STATUS_EXPIRED, OrderStatus::STATUS_CANCELED])) {

                        $payment = Mollie::getLastPayment($mOrder);
                        if (in_array($payment->status, [PaymentStatus::STATUS_AUTHORIZED, PaymentStatus::STATUS_PAID, PaymentStatus::STATUS_PENDING])) {

                            if (session_id() !== $oZahlungSession->cSID) {
                                session_destroy();
                                session_id($oZahlungSession->cSID);
                                $session = Session::getInstance(true, true);
                            } else {
                                $session = Session::getInstance(false, false);
                            }

                            require_once PFAD_ROOT . 'includes/bestellabschluss_inc.php';
                            require_once PFAD_ROOT . 'includes/mailTools.php';

                            $order = fakeBestellung();
                            $order = finalisiereBestellung();
                            $session->cleanUp();

                            if ($order->kBestellung > 0) {
                                $oZahlungSession->nBezahlt = 1;
                                $oZahlungSession->dZeitBezahlt = 'now()';
                                $oZahlungSession->kBestellung = (int)$order->kBestellung;
                                $oZahlungSession->dNotify = strtotime($oZahlungSession->dNotify > 0) ? $oZahlungSession->dNotify : date("Y-m-d H:i:s");
                                Shop::DB()->update('tzahlungsession', 'cZahlungsID', $oZahlungSession->cZahlungsID, $oZahlungSession);
                                Mollie::handleOrder($mOrder, $order->kBestellung);
                                return Mollie::getOrderCompletedRedirect($order->kBestellung, true);
                            }
                        } else {
                            Mollie::JTLMollie()->doLog("Hook 131: Invalid PaymentStatus: {$payment->status} for {$payment->id} ", $logData, LOGLEVEL_ERROR);
                            header('Location: ' . Shop::getURL() . '/bestellvorgang.php?editZahlungsart=1&mollieStatus=' . $payment->status);
                            exit();
                        }

                    } else {
                        Mollie::JTLMollie()->doLog("Hook 131: Invalid OrderStatus: {$mOrder->status} for {$mOrder->id} ", $logData, LOGLEVEL_ERROR);
                        header('Location: ' . Shop::getURL() . '/bestellvorgang.php?editZahlungsart=1&mollieStatus=' . $mOrder->status);
                        exit();
                    }
                } else {
                    Mollie::JTLMollie()->doLog("Hook 131: Could not get Order for {$oZahlungSession->cNotifyID}", $logData, LOGLEVEL_ERROR);
                    header('Location: ' . Shop::getURL() . '/bestellvorgang.php?editZahlungsart=1');
                    exit();
                }
            }
            return Mollie::getOrderCompletedRedirect((int)$oZahlungSession->kBestellung, true);
        }
    }
    ob_end_flush();
} catch (Exception $e) {
    Helper::logExc($e);
}

