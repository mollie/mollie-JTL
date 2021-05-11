<?php


namespace ws_mollie\Model;

/**
 * Class Queue
 * @package ws_mollie\Model
 *
 * @property int $kId;
 * @property string $cType
 * @property string $cData
 * @property string $cResult
 * @property string $dDone
 * @property string $dCreated
 * @property string $dModified
 * @property string|null $bLock
 */
class Queue extends AbstractModel
{

    const TABLE = 'xplugin_ws_mollie_queue';

    const PRIMARY = 'kId';

    public function done($result, $date = null)
    {
        $this->cResult = $result;
        $this->dDone = $date ?: date('Y-m-d H:i:s');
        return $this->save();
    }

    public function save()
    {
        if (!$this->dCreated) {
            $this->dCreated = date('Y-m-d H:i:s');
        }
        $this->dModified = date('Y-m-d H:i:s');
        return parent::save();
    }

}