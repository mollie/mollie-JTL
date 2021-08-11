<?php
/**
 * @copyright 2021 WebStollen GmbH
 * @link https://www.webstollen.de
 */

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
        /** @noinspection SqlResolve */
        $open = Shop::DB()->executeQueryPrepared(sprintf('SELECT * FROM %s WHERE dDone IS NULL ORDER BY dCreated DESC LIMIT 0, :LIMIT;', QueueModel::TABLE), [
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

    /**
     * @param $hook
     * @param QueueModel $todo
     * @throws ApiException
     * @throws IncompatiblePlatform
     * @return bool
     */
    protected static function handleHook($hook, QueueModel $todo)
    {
        $data = unserialize($todo->cData);
        if (array_key_exists('kBestellung', $data)) {
            switch ($hook) {
                case HOOK_BESTELLUNGEN_XML_BESTELLSTATUS:
                    if ((int)$data['kBestellung']) {
                        $checkout = AbstractCheckout::fromBestellung($data['kBestellung']);

                        $result = '';
                        if ((int)$checkout->getBestellung()->cStatus < BESTELLUNG_STATUS_VERSANDT) {
                            return $todo->done("Bestellung noch nicht versendet: {$checkout->getBestellung()->cStatus}");
                        }

                        /** @var $method JTLMollie */
                        if (
                            (int)$data['status']
                            && array_key_exists('status', $data)
                            && $checkout->PaymentMethod()
                            && (strpos($checkout->getModel()->kID, 'tr_') === false)
                            && $checkout->getMollie()
                        ) {
                            /** @var OrderCheckout $checkout */
                            $checkout->handleNotification();
                            if ($checkout->getMollie()->status === OrderStatus::STATUS_COMPLETED) {
                                $result = 'Mollie Status already ' . $checkout->getMollie()->status;
                            } elseif ($checkout->getMollie()->isCreated() || $checkout->getMollie()->isPaid() || $checkout->getMollie()->isAuthorized() || $checkout->getMollie()->isShipping() || $checkout->getMollie()->isPending()) {
                                try {
                                    if ($shipments = Shipment::syncBestellung($checkout)) {
                                        foreach ($shipments as $shipment) {
                                            if (is_string($shipment)) {
                                                $checkout->PaymentMethod()->Log("Shipping-Error: {$shipment}", $checkout->LogData());
                                                $result .= "Shipping-Error: {$shipment};\n";
                                            } else {
                                                $checkout->PaymentMethod()->Log("Order shipped: \n" . print_r($shipment, 1));
                                                $result .= "Order shipped: {$shipment->id};\n";
                                            }
                                        }
                                    } else {
                                        $result = 'No Shipments ready!';
                                    }
                                } catch (RuntimeException $e) {
                                    $result = $e->getMessage();
                                } catch (Exception $e) {
                                    $result = $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString();
                                }
                            } else {
                                $result = sprintf('Unerwarteter Mollie Status "%s" für %s', $checkout->getMollie()->status, $checkout->getBestellung()->cBestellNr);
                            }
                        } else {
                            $result = 'Nothing to do.';
                        }
                    } else {
                        $result = 'kBestellung missing';
                    }
                    $checkout->PaymentMethod()->Log('Queue::handleHook: ' . $result, $checkout->LogData());

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
}
