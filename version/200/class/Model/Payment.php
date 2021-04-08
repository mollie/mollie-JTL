<?php

namespace ws_mollie\Model;

/**
 * Class Payment
 * @package ws_mollie\Model
 *
 * @property string $kID
 * @property int $kBestellung
 * @property string $cMode
 * @property string $cStatus
 * @property string $cHash
 * @property float $fAmount
 * @property string $cOrderNumber
 * @property string $cCurrency
 * @property string $cMethod
 * @property string $cLocale
 * @property bool $bCancelable
 * @property string $cWebhookURL
 * @property string $cRedirectURL
 * @property string $cCheckoutURL
 * @property float $fAmountCaptured
 * @property float $fAmountRefunded
 * @property string $dCreatedAt
 * @property bool $bLockTimeout
 * @property bool $bSynced
 * @property string $cTransactionId
 * @property string $dReminder
 */
class Payment extends AbstractModel
{
    const TABLE = 'xplugin_ws_mollie_payments';

    const PRIMARY = 'kBestellung';

    public function save()
    {
        if (!$this->dCreatedAt) {
            $this->dCreatedAt = date('Y-m-d H:i:s');
        }
        if($this->new){
            $this->dReminder = self::NULL;
        }
        return parent::save();
    }
}
