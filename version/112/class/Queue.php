<?php


namespace ws_mollie;


use Exception;
use Shop;
use ws_mollie\Model\Queue as QueueModel;
use ws_mollie\Traits\Plugin;

class Queue
{

    use Plugin;

    public static function run($limit = 10)
    {

        /** @var QueueModel $todo */
        foreach (self::getOpen($limit) as $todo) {

            if ((list($type, $id) = explode(':', $todo->cType))) {
                try {
                    switch ($type) {
                        case 'webhook':
                            self::handleWebhook($id, $todo);
                            break;

                        case 'hook':
                            self::handleHook((int)$id, $todo);
                            break;
                    }

                } catch (Exception $e) {
                    \Jtllog::writeLog($e->getMessage() . " ({$type}, {$id})");
                    $todo->done("{$e->getMessage()}\n{$e->getFile()}:{$e->getLine()}\n{$e->getTraceAsString()}");
                }
            }
        }
    }

    private static function getOpen($limit)
    {
        $open = Shop::DB()->executeQueryPrepared(sprintf("SELECT * FROM %s WHERE dDone IS NULL ORDER BY dCreated DESC LIMIT 0, :LIMIT;", QueueModel::TABLE), [
            ':LIMIT' => $limit
        ], 2);

        foreach ($open as $_raw) {
            $queueModel = new QueueModel($_raw);
            yield $queueModel;
        }
    }

    protected static function handleWebhook($id, QueueModel $todo)
    {
        //TODO
    }

    protected static function handleHook($hook, QueueModel $todo)
    {
        //TODO
    }

}