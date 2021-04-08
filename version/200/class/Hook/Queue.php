<?php


namespace ws_mollie\Hook;


use Exception;
use Jtllog;
use RuntimeException;
use Shop;
use ws_mollie\Checkout\AbstractCheckout;
use ws_mollie\Checkout\OrderCheckout;
use ws_mollie\Checkout\PaymentCheckout;
use ws_mollie\Model\Queue as QueueModel;

class Queue extends AbstractHook
{

    /**
     * @param array $args_arr
     */
    public static function bestellungInDB(array $args_arr)
    {
        if (array_key_exists('oBestellung', $args_arr)
            && self::Plugin()->oPluginEinstellungAssoc_arr['onlyPaid'] === 'Y'
            && AbstractCheckout::isMollie((int)$args_arr['oBestellung']->kZahlungsart, true)) {

            $args_arr['oBestellung']->cAbgeholt = 'Y';
            Jtllog::writeLog('Switch cAbgeholt for kBestellung: ' . print_r($args_arr['oBestellung']->kBestellung, 1), JTLLOG_LEVEL_NOTICE);
        }
    }

    /**
     * @param array $args_arr
     */
    public static function xmlBestellStatus(array $args_arr)
    {
        if (AbstractCheckout::isMollie((int)$args_arr['oBestellung']->kBestellung)) {
            self::saveToQueue(HOOK_BESTELLUNGEN_XML_BESTELLSTATUS . ':' . (int)$args_arr['oBestellung']->kBestellung, [
                'kBestellung' => $args_arr['oBestellung']->kBestellung,
                'status' => (int)$args_arr['status']
            ]);
        }
    }

    /**
     * @param $hook
     * @param $args_arr
     * @param string $type
     * @return bool
     */
    protected static function saveToQueue($hook, $args_arr, $type = 'hook')
    {
        $mQueue = new QueueModel();
        $mQueue->cType = $type . ':' . $hook;
        $mQueue->cData = serialize($args_arr);
        try {
            return $mQueue->save();
        } catch (Exception $e) {
            Jtllog::writeLog('mollie::saveToQueue: ' . $e->getMessage() . ' - ' . print_r($args_arr, 1));
            return false;
        }
    }

    /**
     * @param array $args_arr
     */
    public static function xmlBearbeiteStorno(array $args_arr)
    {
        if (AbstractCheckout::isMollie((int)$args_arr['oBestellung']->kBestellung)) {
            self::saveToQueue(HOOK_BESTELLUNGEN_XML_BEARBEITESTORNO . ':' . $args_arr['oBestellung']->kBestellung, ['kBestellung' => $args_arr['oBestellung']->kBestellung]);
        }
    }

    /**
     *
     */
    public static function headPostGet()
    {
        if (array_key_exists('mollie', $_REQUEST) && (int)$_REQUEST['mollie'] === 1 && array_key_exists('id', $_REQUEST)) {
            self::saveToQueue($_REQUEST['id'], $_REQUEST['id'], 'webhook');
            exit();
        }
        if (array_key_exists('m_pay', $_REQUEST)) {
            try {
                $raw = Shop::DB()->executeQueryPrepared('SELECT kID FROM `xplugin_ws_mollie_payments` WHERE dReminder IS NOT NULL AND MD5(CONCAT(kID, "-", kBestellung)) = :md5', [
                    ':md5' => $_REQUEST['m_pay']
                ], 1);

                if (!$raw) {
                    throw new RuntimeException(self::Plugin()->oPluginSprachvariableAssoc_arr['errOrderNotFound']);
                }

                if (strpos($raw->cOrderId, 'tr_') === 0) {
                    $checkout = PaymentCheckout::fromID($raw->cOrderId);
                } else {
                    $checkout = OrderCheckout::fromID($raw->cOrderId);
                }
                $checkout->getMollie(true);
                $checkout->updateModel()->saveModel();

                if ($checkout->getBestellung()->dBezahltDatum !== null || in_array($checkout->getModel()->cStatus, ['completed', 'paid', 'authorized', 'pending'])) {
                    throw new RuntimeException(self::Plugin()->oPluginSprachvariableAssoc_arr['errAlreadyPaid']);
                }

                $options = [];
                if (self::Plugin()->oPluginEinstellungAssoc_arr['resetMethod'] !== 'on') {
                    $options['method'] = $checkout->getModel()->cMethod;
                }

                $mollie = $checkout->create($options); // Order::repayOrder($orderModel->getOrderId(), $options, $api);
                $url = $mollie->getCheckoutUrl();

                header('Location: ' . $url);
                exit();

            } catch (RuntimeException $e) {
                // TODO Workaround?
                //$alertHelper = Shop::Container()->getAlertService();
                //$alertHelper->addAlert(Alert::TYPE_ERROR, $e->getMessage(), 'mollie_repay', ['dismissable' => true]);
            } catch (Exception $e) {
                Jtllog::writeLog('mollie:repay:error: ' . $e->getMessage() . "\n" . print_r($_REQUEST, 1));
            }
        }
    }

}