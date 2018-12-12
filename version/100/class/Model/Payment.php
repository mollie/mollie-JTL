<?php

namespace ws_mollie\Model;

use ws_mollie\Model\AbstractModel;

class Payment extends AbstractModel
{

    public const TABLE = 'xplugin_ws_mollie_payments';

    public static function updateFromPayment(\Mollie\Api\Resources\Order $oMolliePayment, $kBestellung = null, $hash = null)
    {
        return \Shop::DB()->executeQueryPrepared('INSERT INTO ' . self::TABLE . ' (kID, kBestellung, cMode, cStatus, cHash, fAmount, cOrderNumber, cCurrency, cMethod, cLocale, bCancelable, cWebhookURL, cRedirectURL, cCheckoutURL, fAmountCaptured, fAmountRefunded, dCreatedAt) '
            . 'VALUES (:kID, :kBestellung, :cMode, :cStatus, :cHash, :fAmount, :cOrderNumber, :cCurrency, :cMethod, :cLocale, :bCancelable, :cWebhookURL, :cRedirectURL, :cCheckoutURL, :fAmountCaptured, :fAmountRefunded, :dCreatedAt) '
            . 'ON DUPLICATE KEY UPDATE kBestellung = :kBestellung1, cStatus = :cStatus1, cMethod = :cMethod1, bCancelable = :bCancelable1, fAmountCaptured = :fAmountCaptured1, fAmountRefunded = :fAmountRefunded1', [
            ':kID' => $oMolliePayment->id,
            ':kBestellung' => (int)$kBestellung ?: NULL,
            ':kBestellung1' => (int)$kBestellung ?: NULL,
            ':cMode' => $oMolliePayment->mode,
            ':cStatus' => $oMolliePayment->status,
            ':cStatus1' => $oMolliePayment->status,
            ':cHash' => $hash,
            ':fAmount' => $oMolliePayment->amount->value,
            ':cOrderNumber' => $oMolliePayment->orderNumber,
            ':cCurrency' => $oMolliePayment->amount->currency,
            ':cMethod' => $oMolliePayment->method,
            ':cMethod1' => $oMolliePayment->method,
            ':cLocale' => $oMolliePayment->locale,
            ':bCancelable' => $oMolliePayment->isCancelable,
            ':bCancelable1' => $oMolliePayment->isCancelable,
            ':cWebhookURL' => $oMolliePayment->webhookUrl,
            ':cRedirectURL' => $oMolliePayment->redirectUrl,
            ':cCheckoutURL' => $oMolliePayment->getCheckoutUrl(),
            ':fAmountCaptured' => $oMolliePayment->amountCaptured,
            ':fAmountCaptured1' => $oMolliePayment->amountCaptured,
            ':fAmountRefunded' => $oMolliePayment->amountRefunded,
            ':fAmountRefunded1' => $oMolliePayment->amountRefunded,
            ':dCreatedAt' => $oMolliePayment->createdAt ? date('Y-m-d H:i:s', strtotime($oMolliePayment->createdAt)) : null,
        ], 3);
    }
}