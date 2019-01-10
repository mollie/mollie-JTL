<?php

// TODO: Some more logging

if (strpos($_SERVER['PHP_SELF'], 'bestellabschluss') === false) {
    return;
}

require_once __DIR__ . '/../class/Helper.php';
try {
    if (!\ws_mollie\Helper::init()) {
        //    return;
    }
    ob_start();
    if (array_key_exists('mollie', $_REQUEST)) {
        $payment = \Shop::DB()->executeQueryPrepared("SELECT * FROM " . \ws_mollie\Model\Payment::TABLE . " WHERE cHash = :cHash", [':cHash' => $_REQUEST['mollie']], 1);
        if ((int)$payment->kBestellung) {


            $bestellid = \Shop::DB()->executeQueryPrepared("SELECT * FROM tbestellid WHERE kBestellung = :kBestellung", [':kBestellung' => $payment->kBestellung], 1);
            if ($bestellid) {
                header('Location: ' . SHop::getURL() . '/bestellabschluss.php?i=' . $bestellid->cId);
                exit();
            }
        } elseif ($payment) {

            $mollie = new \Mollie\Api\MollieApiClient();
            $mollie->setApiKey(\ws_mollie\Helper::getSetting("api_key"));

            $order = $mollie->orders->get($payment->kID);

            if ($order) {
                $session = Session::getInstance();
                require_once PFAD_ROOT . 'includes/bestellabschluss_inc.php';
                require_once PFAD_ROOT . 'includes/mailTools.php';

                $oBestellung = fakeBestellung();
                $oBestellung = finalisiereBestellung();

                $order->orderNumber = $oBestellung->cBestellNr;
                \ws_mollie\Model\Payment::updateFromPayment($order, $oBestellung->kBestellung);

                $bestellid = (isset($oBestellung->kBestellung) && $oBestellung->kBestellung > 0)
                    ? Shop::DB()->select('tbestellid', 'kBestellung', $oBestellung->kBestellung)
                    : false;

                if ($bestellid) {

                    $session->cleanUp();
                    $linkHelper = LinkHelper::getInstance();

                    $orderCompleteURL = $linkHelper->getStaticRoute('bestellabschluss.php', true);
                    $successPaymentURL = (!empty($bestellid->cId)) ?
                        ($orderCompleteURL . '?i=' . $bestellid->cId)
                        : Shop::getURL();
                    header('Location: ' . $successPaymentURL, 302);
                    exit();
                }
            }
        }
    }

    ob_end_flush();

} catch (Exception $e) {
    \ws_mollie\Helper::logExc($e);
}