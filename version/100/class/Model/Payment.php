<?php

/**
 * @copyright 2022 WebStollen GmbH
 * @link https://www.webstollen.de
 */

namespace ws_mollie\Model;

use Bestellung;
use Mollie\Api\Resources\Order;
use Shop;

class Payment extends AbstractModel
{
    const TABLE = 'xplugin_ws_mollie_payments';

    public static function updateFromPayment(Order $oMolliePayment, $kBestellung = null, $hash = null)
    {
        $data = [
            ':kID'              => $oMolliePayment->id,
            ':kBestellung'      => (int)$kBestellung ?: null,
            ':kBestellung1'     => (int)$kBestellung ?: null,
            ':cMode'            => $oMolliePayment->mode,
            ':cStatus'          => $oMolliePayment->status,
            ':cStatus1'         => $oMolliePayment->status,
            ':cHash'            => $hash,
            ':fAmount'          => $oMolliePayment->amount->value,
            ':cOrderNumber'     => $oMolliePayment->orderNumber,
            ':cOrderNumber1'    => $oMolliePayment->orderNumber,
            ':cCurrency'        => $oMolliePayment->amount->currency,
            ':cMethod'          => $oMolliePayment->method,
            ':cMethod1'         => $oMolliePayment->method,
            ':cLocale'          => $oMolliePayment->locale,
            ':bCancelable'      => $oMolliePayment->isCancelable,
            ':bCancelable1'     => $oMolliePayment->isCancelable,
            ':cWebhookURL'      => $oMolliePayment->webhookUrl,
            ':cRedirectURL'     => $oMolliePayment->redirectUrl,
            ':cCheckoutURL'     => $oMolliePayment->getCheckoutUrl(),
            ':cCheckoutURL1'    => $oMolliePayment->getCheckoutUrl(),
            ':fAmountCaptured'  => $oMolliePayment->amountCaptured ? $oMolliePayment->amountCaptured->value : null,
            ':fAmountCaptured1' => $oMolliePayment->amountCaptured ? $oMolliePayment->amountCaptured->value : null,
            ':fAmountRefunded'  => $oMolliePayment->amountRefunded ? $oMolliePayment->amountRefunded->value : null,
            ':fAmountRefunded1' => $oMolliePayment->amountRefunded ? $oMolliePayment->amountRefunded->value : null,
            ':dCreatedAt'       => $oMolliePayment->createdAt ? date('Y-m-d H:i:s', strtotime($oMolliePayment->createdAt)) : null,
        ];

        return Shop::DB()->executeQueryPrepared(
            'INSERT INTO ' . self::TABLE . ' (kID, kBestellung, cMode, cStatus, cHash, fAmount, cOrderNumber, cCurrency, cMethod, cLocale, bCancelable, cWebhookURL, cRedirectURL, cCheckoutURL, fAmountCaptured, fAmountRefunded, dCreatedAt) '
            . 'VALUES (:kID, :kBestellung, :cMode, :cStatus, :cHash, :fAmount, :cOrderNumber, :cCurrency, :cMethod, :cLocale, :bCancelable, :cWebhookURL, :cRedirectURL, IF(:cCheckoutURL IS NULL, cCheckoutURL, :cCheckoutURL1), :fAmountCaptured, :fAmountRefunded, :dCreatedAt) '
            . 'ON DUPLICATE KEY UPDATE kBestellung = :kBestellung1, cOrderNumber = :cOrderNumber1, cStatus = :cStatus1, cMethod = :cMethod1, bCancelable = :bCancelable1, fAmountCaptured = :fAmountCaptured1, fAmountRefunded = :fAmountRefunded1',
            $data,
            3
        );
    }

    public static function getPayment($kBestellung)
    {
        $payment = Shop::DB()->executeQueryPrepared('SELECT * FROM ' . self::TABLE . ' WHERE kBestellung = :kBestellung', [':kBestellung' => $kBestellung], 1);
        if ($payment && $payment->kBestellung) {
            $payment->oBestellung = new Bestellung($payment->kBestellung, false);
        }

        return $payment;
    }

    public static function getPaymentMollie($kID)
    {
        $payment = Shop::DB()->executeQueryPrepared('SELECT * FROM ' . self::TABLE . ' WHERE kID = :kID', [':kID' => $kID], 1);
        if ($payment && $payment->kBestellung) {
            $payment->oBestellung = new Bestellung($payment->kBestellung, false);
        }

        return $payment;
    }

    public static function getPaymentHash($cHash)
    {
        $payment = Shop::DB()->executeQueryPrepared('SELECT * FROM ' . self::TABLE . ' WHERE cHash = :cHash', [':cHash' => $cHash], 1);
        if ($payment && $payment->kBestellung) {
            $payment->oBestellung = new Bestellung($payment->kBestellung, false);
        }

        return $payment;
    }
}
