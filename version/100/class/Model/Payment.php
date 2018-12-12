<?php

namespace ws_mollie\Model;

use ws_mollie\Model\AbstractModel;

class Payment extends AbstractModel {
    
    public const TABLE = 'xplugin_ws_mollie_payments';
    
    public static function updateFromPayment(\Mollie\Api\Resources\Payment $oMolliePayment, $kBestellung = null, $hash = null){
        return \Shop::DB()->executeQueryPrepared('INSERT INTO ' . self::TABLE . ' (kID, kBestellung, cStatus, fAmount, cCurrency, cMethod, cHash, dCreatedAt, dPaidAt) VALUES (:kID, :kBestellung, :cStatus, :fAmount, :cCurrency, :cMethod, :cHash, :dCreatedAt, :dPaidAt) '
            .' ON DUPLICATE KEY UPDATE kBestellung = :kBestellung1, cStatus = :cStatus1, cMethod = :cMethod1, dPaidAt = :dPaidAt1', [
            ':kID' => $oMolliePayment->id,
            ':kBestellung' => $kBestellung,
            ':kBestellung1' => $kBestellung,
            ':cStatus' => $oMolliePayment->status,
            ':cStatus1' => $oMolliePayment->status,
            ':fAmount' => $oMolliePayment->amount->value,
            ':cCurrency' => $oMolliePayment->amount->currency,
            ':cMethod' => $oMolliePayment->method,
            ':cMethod1' => $oMolliePayment->method,
            ':cHash' => $hash,
            ':dCreatedAt' => $oMolliePayment->createdAt ? date('Y-m-d H:i:s', strtotime($oMolliePayment->createdAt)) : null,
            ':dPaidAt' => $oMolliePayment->paidAt ? date('Y-m-d H:i:s', strtotime($oMolliePayment->paidAt)) : null,
            ':dPaidAt1' => $oMolliePayment->paidAt ? date('Y-m-d H:i:s', strtotime($oMolliePayment->paidAt)) : null,
        ], 3);
    }
}