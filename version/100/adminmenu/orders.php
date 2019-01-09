<?php

require_once __DIR__ . '/../class/Helper.php';
try {
    if (!\ws_mollie\Helper::init()) {
        echo "Kein gültige Lizenz?";
        return;
    }

    global $oPlugin;

    $ordersMsgs = [];

    if (array_key_exists('action', $_REQUEST)) {
        switch ($_REQUEST['action']) {
            case 'order':
                if (!array_key_exists('id', $_REQUEST)) {
                    $ordersMsgs[] = (object)['type' => 'danger', 'text' => 'Keine ID angeben!'];
                    break;
                }

                $mollie = new \Mollie\Api\MollieApiClient();
                $mollie->setApiKey(\ws_mollie\Helper::getSetting("api_key"));

                $order = $mollie->orders->get($_REQUEST['id']);
                $payment = \ws_mollie\Model\Payment::getPaymentMollie($_REQUEST['id']);
                if ($payment) {
                    $oBestellung = new Bestellung($payment->kBestellung, false);
                    if ($oBestellung->kBestellung && $oBestellung->cBestellNr !== $payment->cOrderNumber) {
                        Shop::DB()->executeQueryPrepared("UPDATE xplugin_ws_mollie_payments SET cOrderNumber = :cBestellNr WHERE kID = :kID", [
                            ':cBestellNr' => $oBestellung->cBestellNr,
                            ':kID' => $payment->kID,
                        ], 3);
                    }
                }

                $logs = Shop::DB()->executeQueryPrepared("SELECT * FROM tzahlungslog WHERE cLogData LIKE :kBestellung OR cLogData LIKE :cBestellNr OR cLogData LIKE :MollieID ORDER BY dDatum DESC", [
                    ':kBestellung' => '%#' . $payment->kBestellung . '%',
                    ':cBestellNr' => '%§' . $payment->cOrderNumber . '%',
                    ':MollieID' => '%$' . $payment->kID . '%',
                ], 2);

                Shop::Smarty()->assign('payment', $payment)
                    ->assign('oBestellung', $oBestellung)
                    ->assign('order', $order)
                    ->assign('logs', $logs);
                Shop::Smarty()->display($oPlugin->cAdminmenuPfad . '/tpl/order.tpl');
                return;
        }
    }


    $payments = Shop::DB()->executeQueryPrepared("SELECT * FROM xplugin_ws_mollie_payments", [], 2);

    Shop::Smarty()->assign('payments', $payments)
        ->assign('ordersMsgs', $ordersMsgs);

    Shop::Smarty()->display($oPlugin->cAdminmenuPfad . '/tpl/orders.tpl');

} catch (Exception $e) {
    echo "<div class='alert alert-danger'>{$e->getMessage()}</div>";
    \ws_mollie\Helper::logExc($e);
}