<?php


namespace ws_mollie;


use Exception;
use Generator;
use Jtllog;
use RuntimeException;
use Shop;
use ws_mollie\Checkout\AbstractCheckout;
use ws_mollie\Model\Queue as QueueModel;
use ws_mollie\Traits\Plugin;

class Queue
{

    use Plugin;

    public static function run($limit = 10)
    {

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
                    Jtllog::writeLog($e->getMessage() . " ({$type}, {$id})");
                    $todo->done("{$e->getMessage()}\n{$e->getFile()}:{$e->getLine()}\n{$e->getTraceAsString()}");
                }
            }
        }
    }

    /**
     * @param $limit
     * @return Generator|QueueModel[]
     */
    private static function getOpen($limit)
    {
        $open = Shop::DB()->executeQueryPrepared(sprintf("SELECT * FROM %s WHERE dDone IS NULL ORDER BY dCreated DESC LIMIT 0, :LIMIT;", QueueModel::TABLE), [
            ':LIMIT' => $limit
        ], 2);

        foreach ($open as $_raw) {
            yield new QueueModel($_raw);
        }
    }

    protected static function handleWebhook($id, QueueModel $todo)
    {
        $checkout = AbstractCheckout::fromID($id);
        if ($checkout->getBestellung()->kBestellung && $checkout->PaymentMethod()) {
            $checkout->handleNotification();
            return $todo->done('Status: ' . $checkout->getMollie()->status);
        }
        throw new RuntimeException("Bestellung oder Zahlungsart konnte nicht geladen werden: {$id}");
    }

    protected static function handleHook($hook, QueueModel $todo)
    {
        //TODO
    }

}