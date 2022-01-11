<?php

/**
 * @copyright 2022 WebStollen GmbH
 * @link https://www.webstollen.de
 */

namespace ws_mollie;

use Bestellung;
use Exception;
use JTLMollie;
use Lieferschein;
use Lieferscheinpos;
use Mollie\Api\Resources\Order;
use Mollie\Api\Types\OrderStatus;
use Shop;
use Shopsetting;
use stdClass;
use WarenkorbPos;
use ws_mollie\Model\Payment;

abstract class Mollie
{
    protected static $_jtlmollie;

    /**
     * @param $kBestellung
     * @param bool $redirect
     * @return bool|string
     */
    public static function getOrderCompletedRedirect($kBestellung, $redirect = true)
    {
        $mode = Shopsetting::getInstance()->getValue(CONF_KAUFABWICKLUNG, 'bestellabschluss_abschlussseite');

        $bestellid = Shop::DB()->select('tbestellid ', 'kBestellung', (int)$kBestellung);
        $url       = Shop::getURL() . '/bestellabschluss.php?i=' . $bestellid->cId;


        if ($mode == 'S' || !$bestellid) { // Statusseite
            $bestellstatus = Shop::DB()->select('tbestellstatus', 'kBestellung', (int)$kBestellung);
            $url           = Shop::getURL() . '/status.php?uid=' . $bestellstatus->cUID;
        }

        if ($redirect) {
            header('Location: ' . $url);
            exit();
        }

        return $url;
    }

    /**
     * @param Order $order
     * @param $kBestellung
     * @param bool $newStatus
     * @throws Exception
     * @return array
     */
    public static function getShipmentOptions(Order $order, $kBestellung, $newStatus = false)
    {
        if (!$order || !$kBestellung) {
            throw new Exception('Mollie::getShipmentOptions: order and kBestellung are required!');
        }


        $oBestellung = new Bestellung($kBestellung, true);
        if ($newStatus === false) {
            $newStatus = $oBestellung->cStatus;
        }
        $options = [];

        // Tracking Data
        if ($oBestellung->cTracking) {
            $tracking            = new stdClass();
            $tracking->carrier   = $oBestellung->cVersandartName;
            $tracking->url       = $oBestellung->cTrackingURL;
            $tracking->code      = $oBestellung->cTracking;
            $options['tracking'] = $tracking;
        }

        switch ((int)$newStatus) {
            case BESTELLUNG_STATUS_VERSANDT:
                $options['lines'] = [];

                break;
            case BESTELLUNG_STATUS_TEILVERSANDT:
                $lines = [];
                foreach ($order->lines as $i => $line) {
                    if (($quantity = self::getBestellPosSent($line->sku, $oBestellung)) !== false && ($quantity - $line->quantityShipped) > 0) {
                        $x       = $quantity - $line->quantityShipped;
                        $lines[] = (object)[
                            'id'       => $line->id,
                            'quantity' => $x,
                            'amount'   => (object)[
                                'currency' => $line->totalAmount->currency,
                                'value'    => number_format($x * $line->unitPrice->value, 2),
                            ],
                        ];
                    }
                }
                if (count($lines)) {
                    $options['lines'] = $lines;
                }

                break;
            case BESTELLUNG_STATUS_STORNO:
                $options = null;

                break;
        }

        return $options;
    }

    /**
     * Returns amount of sent items for SKU
     * @param $sku
     * @param Bestellung $oBestellung
     * @throws Exception
     * @return float|int
     */
    public static function getBestellPosSent($sku, Bestellung $oBestellung)
    {
        if ($sku === null) {
            return 1;
        }
        /** @var WarenkorbPos $oPosition */
        foreach ($oBestellung->Positionen as $oPosition) {
            if ($oPosition->cArtNr === $sku) {
                $sent = 0;
                /** @var Lieferschein $oLieferschein */
                foreach ($oBestellung->oLieferschein_arr as $oLieferschein) {
                    /** @var Lieferscheinpos $oLieferscheinPos */
                    foreach ($oLieferschein->oLieferscheinPos_arr as $oLieferscheinPos) {
                        if ($oLieferscheinPos->getBestellPos() == $oPosition->kBestellpos) {
                            $sent += $oLieferscheinPos->getAnzahl();
                        }
                    }
                }

                return $sent;
            }
        }

        return false;
    }

    /**
     * @param Order $order
     * @param null  $kBestellung
     * @throws Exception
     * @return bool
     */
    public static function handleOrder(Order $order, $kBestellung)
    {
        $logData = '$' . $order->id . '#' . $kBestellung . '§' . $order->orderNumber;

        $oBestellung = new Bestellung($kBestellung);
        if ($oBestellung->kBestellung) {
            $order->orderNumber = $oBestellung->cBestellNr;
            Payment::updateFromPayment($order, $kBestellung);

            $oIncomingPayment = Shop::DB()->executeQueryPrepared('SELECT * FROM tzahlungseingang WHERE cHinweis = :cHinweis AND kBestellung = :kBestellung', [':cHinweis' => $order->id, ':kBestellung' => $oBestellung->kBestellung], 1);
            if (!$oIncomingPayment) {
                $oIncomingPayment = new stdClass();
            }

            // 2. Check PaymentStatus
            switch ($order->status) {
                case OrderStatus::STATUS_PAID:
                case OrderStatus::STATUS_COMPLETED:
                    $oIncomingPayment->fBetrag  = $order->amount->value;
                    $oIncomingPayment->cISO     = $order->amount->curreny;
                    $oIncomingPayment->cHinweis = $order->id;
                    self::JTLMollie()->addIncomingPayment($oBestellung, $oIncomingPayment);
                    self::JTLMollie()->setOrderStatusToPaid($oBestellung);
                    self::JTLMollie()->doLog('PaymentStatus: ' . $order->status . ' => Zahlungseingang (' . $order->amount->value . ')', $logData, LOGLEVEL_DEBUG);

                    break;
                case OrderStatus::STATUS_SHIPPING:
                case OrderStatus::STATUS_AUTHORIZED:
                case OrderStatus::STATUS_PENDING:
                    self::JTLMollie()->setOrderStatusToPaid($oBestellung);
                    self::JTLMollie()->doLog('PaymentStatus: ' . $order->status . ' => Bestellung bezahlt', $logData, LOGLEVEL_NOTICE);

                    break;
                case OrderStatus::STATUS_CANCELED:
                case OrderStatus::STATUS_EXPIRED:
                    self::JTLMollie()->doLog('PaymentStatus: ' . $order->status, $logData, LOGLEVEL_ERROR);

                    break;
            }

            return true;
        }

        return false;
    }

    /**
     * @throws Exception
     * @return JTLMollie
     */
    public static function JTLMollie()
    {
        if (self::$_jtlmollie === null) {
            $pza = Shop::DB()->select('tpluginzahlungsartklasse', 'cClassName', 'JTLMollie');
            if (!$pza) {
                throw new Exception('Mollie Zahlungsart nicht in DB gefunden!');
            }
            require_once __DIR__ . '/../paymentmethod/JTLMollie.php';
            self::$_jtlmollie = new JTLMollie($pza->cModulId);
        }

        return self::$_jtlmollie;
    }
}
