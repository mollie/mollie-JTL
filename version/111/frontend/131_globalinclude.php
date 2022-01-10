<?php
/**
 * @copyright 2022 WebStollen GmbH
 * @link https://www.webstollen.de
 */

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
                Mollie::JTLMollie()->doLog("Hook 131: Bestellung noch nicht finalisiert ({$oZahlungSession->cNotifyID})", $logData, LOGLEVEL_DEBUG);
                // Bestellung noch nicht finalisiert
                $mOrder = JTLMollie::API()->orders->get($oZahlungSession->cNotifyID, ['embed' => 'payments']);
                if ($mOrder && $mOrder->id === $oZahlungSession->cNotifyID) {
                    $lock    = new \ws_mollie\ExclusiveLock('mollie_' . $mOrder->id, PFAD_ROOT . PFAD_COMPILEDIR);
                    $logged  = false;
                    $maxWait = 120;
                    while (!$lock->lock() && $maxWait > 0) {
                        if (!$logged) {
                            Mollie::JTLMollie()->doLog("Hook 131: Order currently locked ({$oZahlungSession->cNotifyID})", $logData, LOGLEVEL_DEBUG);
                            $logged = microtime(true);
                        }
                        usleep(1000000);
                        $maxWait--;
                    }

                    if ($logged) {
                        Mollie::JTLMollie()->doLog('Hook 131: Order unlocked (after ' . round(microtime(true) - $logged, 2) . "s - maxWait left: {$maxWait})", $logData, LOGLEVEL_DEBUG);
                    } else {
                        Mollie::JTLMollie()->doLog("Hook 131: Order locked - maxWait left: {$maxWait})", $logData, LOGLEVEL_DEBUG);
                    }

                    $oZahlungSession = JTLMollie::getZahlungSession($_REQUEST['mollie']);
                    if ((int)$oZahlungSession->kBestellung) {
                        Mollie::JTLMollie()->doLog("Hook 131: Order finalized already ({$oZahlungSession->kBestellung}) => redirect", $logData, LOGLEVEL_DEBUG);

                        return Mollie::getOrderCompletedRedirect($oZahlungSession->kBestellung, true);
                    }

                    Mollie::JTLMollie()->doLog("Hook 131: Order {$mOrder->id} - {$mOrder->status} <br/><pre>" . print_r($mOrder, 1) . '</pre>', $logData, LOGLEVEL_DEBUG);
                    if (!in_array($mOrder->status, [OrderStatus::STATUS_EXPIRED, OrderStatus::STATUS_CANCELED])) {
                        $payment = Mollie::getLastPayment($mOrder);
                        if (in_array($payment->status, [PaymentStatus::STATUS_AUTHORIZED, PaymentStatus::STATUS_PAID, PaymentStatus::STATUS_PENDING])) {
                            if (session_id() !== $oZahlungSession->cSID) {
                                Mollie::JTLMollie()->doLog('Hook 131: Switch to PaymentSession <br/><pre>' . print_r([session_id(), $oZahlungSession], 1) . '</pre>', $logData, LOGLEVEL_DEBUG);
                                session_destroy();
                                session_id($oZahlungSession->cSID);
                                $session = Session::getInstance(true, true);
                            } else {
                                Mollie::JTLMollie()->doLog('Hook 131: Already in PaymentSession <br/><pre>' . print_r([session_id(), $oZahlungSession], 1) . '</pre>', $logData, LOGLEVEL_DEBUG);
                                $session = Session::getInstance(false, false);
                            }

                            require_once PFAD_ROOT . 'includes/bestellabschluss_inc.php';
                            require_once PFAD_ROOT . 'includes/mailTools.php';

                            $order = fakeBestellung();
                            $order = finalisiereBestellung();
                            $session->cleanUp();
                            $logData .= '#' . $order->kBestellung . 'ß' . $order->cBestellNr;
                            Mollie::JTLMollie()->doLog('Hook 131: Bestellung finalisiert <br/><pre>' . print_r([$order->kBestellung, $order->cBestellNr], 1) . '</pre>', $logData, LOGLEVEL_DEBUG);

                            if ($order->kBestellung > 0) {
                                Mollie::JTLMollie()->doLog("Hook 131: Finalisierung erfolgreich, kBestellung: {$order->kBestellung} / {$order->cBestellNr}", $logData, LOGLEVEL_DEBUG);
                                $oZahlungSession->nBezahlt     = 1;
                                $oZahlungSession->dZeitBezahlt = 'now()';
                                $oZahlungSession->kBestellung  = (int)$order->kBestellung;
                                $oZahlungSession->dNotify      = strtotime($oZahlungSession->dNotify > 0) ? $oZahlungSession->dNotify : date('Y-m-d H:i:s');
                                Shop::DB()->update('tzahlungsession', 'cZahlungsID', $oZahlungSession->cZahlungsID, $oZahlungSession);
                                Mollie::handleOrder($mOrder, $order->kBestellung);

                                return Mollie::getOrderCompletedRedirect($order->kBestellung, true);
                            }
                            Mollie::JTLMollie()->doLog('Hook 131: Fionalisierung fehlgeschlagen <br/><pre>' . print_r($order, 1) . '</pre>', $logData, LOGLEVEL_ERROR);
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
            } else {
                Mollie::JTLMollie()->doLog("Hook 131: already finalized => redirect / kBestellung:{$oZahlungSession->kBestellung} && cNotifyID:{$oZahlungSession->cNotifyID}", $logData, LOGLEVEL_NOTICE);
            }

            return Mollie::getOrderCompletedRedirect((int)$oZahlungSession->kBestellung, true);
        }
    }
    ob_end_flush();
} catch (Exception $e) {
    Helper::logExc($e);
}
