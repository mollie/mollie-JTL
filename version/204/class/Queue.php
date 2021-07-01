<?php


namespace ws_mollie;


use Exception;
use Generator;
use Jtllog;
use JTLMollie;
use Mollie\Api\Exceptions\ApiException;
use Mollie\Api\Exceptions\IncompatiblePlatform;
use Mollie\Api\Types\OrderStatus;
use RuntimeException;
use Shop;
use ws_mollie\Checkout\AbstractCheckout;
use ws_mollie\Checkout\OrderCheckout;
use ws_mollie\Model\Queue as QueueModel;
use ws_mollie\Traits\Plugin;

class Queue
{

    use Plugin;

    /**
     * @param $delay
     * @return bool
     */
    public static function storno($delay)
    {
        if (!$delay) {
            return true;
        }

        $open = Shop::DB()->executeQueryPrepared("SELECT p.kBestellung, b.cStatus FROM xplugin_ws_mollie_payments p "
            . "JOIN tbestellung b ON b.kBestellung = p.kBestellung "
            . "WHERE b.cAbgeholt = 'Y' AND NOT p.bSynced AND b.cStatus IN ('1', '2') AND p.dCreatedAt < NOW() - INTERVAL :d HOUR",
            [':d' => $delay], 2);

        foreach ($open as $o) {
            try {

                $checkout = AbstractCheckout::fromBestellung($o->kBestellung);
                $pm = $checkout->PaymentMethod();
                if ($pm::ALLOW_AUTO_STORNO && $pm::METHOD === $checkout->getMollie()->method) {
                    if ($checkout->getBestellung()->cAbgeholt === 'Y' && (bool)$checkout->getModel()->bSynced === false) {
                        if (!in_array($checkout->getMollie()->status, [OrderStatus::STATUS_PAID, OrderStatus::STATUS_COMPLETED, OrderStatus::STATUS_AUTHORIZED], true)) {
                            $checkout->storno();
                        } else {
                            $checkout->Log(sprintf('AutoStorno: Bestellung bezahlt? %s - Method: %s', $checkout->getMollie()->status, $checkout->getMollie()->method), LOGLEVEL_ERROR);
                        }
                    } else {
                        $checkout->Log('AutoStorno: bereits zur WAWI synchronisiert.', LOGLEVEL_ERROR);
                    }
                }

            } catch (Exception $e) {
                Helper::logExc($e);
            }
        }
        return true;
    }

    /**
     * @param int $limit
     */
    public static function run($limit = 10)
    {

        foreach (self::getOpen($limit) as $todo) {

            if (!self::lock($todo)) {
                Jtllog::writeLog(sprintf('%s already locked since %s', $todo->kId, $todo->bLock ?: 'just now'));
                continue;
            }

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
                    Jtllog::writeLog($e->getMessage() . " ($type, $id)");
                    $todo->done("{$e->getMessage()}\n{$e->getFile()}:{$e->getLine()}\n{$e->getTraceAsString()}");
                }
            }

            self::unlock($todo);

        }
    }

    /**
     * @param $limit
     * @return Generator|QueueModel[]
     * @noinspection PhpReturnDocTypeMismatchInspection
     * @noinspection SqlResolve
     */
    private static function getOpen($limit)
    {
        // TODO: DOKU!
        if(!defined('MOLLIE_HOOK_DELAY')){
            define('MOLLIE_HOOK_DELAY', 3);
        }
        $open = Shop::DB()->executeQueryPrepared(sprintf("SELECT * FROM %s WHERE dDone IS NULL AND `bLock` IS NULL AND (cType LIKE 'webhook:%%' OR (cType LIKE 'hook:%%') AND dCreated < DATE_SUB(NOW(), INTERVAL ".(int)MOLLIE_HOOK_DELAY." MINUTE)) ORDER BY dCreated DESC LIMIT 0, :LIMIT;", QueueModel::TABLE), [
            ':LIMIT' => $limit
        ], 2);

        foreach ($open as $_raw) {
            yield new QueueModel($_raw);
        }
    }

    /**
     * @param $todo
     * @return bool
     * @noinspection SqlResolve
     */
    protected static function lock($todo)
    {
        return $todo->kId && Shop::DB()->executeQueryPrepared(sprintf('UPDATE %s SET `bLock` = NOW() WHERE `bLock` IS NULL AND kId = :kId', QueueModel::TABLE), [
                'kId' => $todo->kId
            ], 3) >= 1;

    }

    /**
     * @param $id
     * @param QueueModel $todo
     * @return bool
     * @throws Exception
     */
    protected static function handleWebhook($id, QueueModel $todo)
    {
        $checkout = AbstractCheckout::fromID($id);
        if ($checkout->getBestellung()->kBestellung && $checkout->PaymentMethod()) {
            $checkout->handleNotification();
            return $todo->done('Status: ' . $checkout->getMollie()->status);
        }
        throw new RuntimeException("Bestellung oder Zahlungsart konnte nicht geladen werden: $id");
    }

    /**
     * @param $hook
     * @param QueueModel $todo
     * @return bool
     * @throws ApiException
     * @throws IncompatiblePlatform
     * @throws Exception
     */
    protected static function handleHook($hook, QueueModel $todo)
    {
        $data = unserialize($todo->cData);
        if (array_key_exists('kBestellung', $data)) {
            switch ($hook) {
                case HOOK_BESTELLUNGEN_XML_BESTELLSTATUS:
                    if ((int)$data['kBestellung']) {
                        $checkout = AbstractCheckout::fromBestellung($data['kBestellung']);

                        $status = array_key_exists('status', $data) ? (int)$data['status'] : 0;
                        $result = "";
                        if (!$status || $status < BESTELLUNG_STATUS_VERSANDT) {
                            return $todo->done("Bestellung noch nicht versendet: {$checkout->getBestellung()->cStatus}");
                        }

                        /** @var $method JTLMollie */
                        if ((strpos($checkout->getModel()->kID, 'tr_') === false)
                            && $checkout->PaymentMethod()
                            && $checkout->getMollie()) {
                            /** @var OrderCheckout $checkout */
                            $checkout->handleNotification();
                            if ($checkout->getMollie()->status === OrderStatus::STATUS_COMPLETED) {
                                $result = 'Mollie Status already ' . $checkout->getMollie()->status;
                            } else if ($checkout->getMollie()->isCreated() || $checkout->getMollie()->isPaid() || $checkout->getMollie()->isAuthorized() || $checkout->getMollie()->isShipping() || $checkout->getMollie()->isPending()) {
                                try {
                                    if ($shipments = Shipment::syncBestellung($checkout)) {
                                        foreach ($shipments as $shipment) {
                                            if (is_string($shipment)) {
                                                $checkout->PaymentMethod()->Log("Shipping-Error: $shipment", $checkout->LogData());
                                                $result .= "Shipping-Error: $shipment;\n";
                                            } else {
                                                $checkout->PaymentMethod()->Log("Order shipped: \n" . print_r($shipment, 1));
                                                $result .= "Order shipped: $shipment->id;\n";
                                            }
                                        }
                                    } else {
                                        $result = 'No Shipments ready!';
                                    }
                                } catch (RuntimeException $e) {
                                    $result = $e->getMessage();
                                } catch (Exception $e) {
                                    $result = $e->getMessage() . "\n" . $e->getFile() . ":" . $e->getLine() . "\n" . $e->getTraceAsString();
                                }
                            } else {
                                $result = sprintf('Unerwarteter Mollie Status "%s" für %s', $checkout->getMollie()->status, $checkout->getBestellung()->cBestellNr);
                            }
                        } else {
                            $result = 'Nothing to do.';
                        }
                        $checkout->PaymentMethod()->Log("Queue::handleHook: " . $result, $checkout->LogData());
                    } else {
                        $result = "kBestellung missing";
                    }
                    return $todo->done($result);

                case HOOK_BESTELLUNGEN_XML_BEARBEITESTORNO:
                    if (self::Plugin()->oPluginEinstellungAssoc_arr['autoRefund'] !== 'Y') {
                        throw new RuntimeException('Auto-Refund disabled');
                    }

                    $checkout = AbstractCheckout::fromBestellung((int)$data['kBestellung']);
                    return $todo->done($checkout->cancelOrRefund());
            }
        }
        return false;
    }

    /**
     * @param $todo
     * @return bool
     */
    protected static function unlock($todo)
    {
        return $todo->kId && Shop::DB()->executeQueryPrepared(sprintf('UPDATE %s SET `bLock` = NULL WHERE kId = :kId', QueueModel::TABLE), [
                'kId' => $todo->kId
            ], 3) >= 1;
    }

}