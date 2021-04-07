<?php
require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/JTLMollie.php';

class JTLMollieBanktransfer extends JTLMollie
{
    const METHOD = \Mollie\Api\Types\PaymentMethod::BANKTRANSFER;

    const ALLOW_PAYMENT_BEFORE_ORDER = false;

    public function getPaymentOptions(Bestellung $order, $apiType): array
    {
        $paymentOptions = [];
        if ($apiType === 'payment') {
            $paymentOptions['billingEmail'] = $order->oRechnungsadresse->cMail;
            $paymentOptions['locale'] = \ws_mollie\Checkout\Payment\Locale::getLocale(Session::getInstance()->Language()->getIso(), $order->oRechnungsadresse->cLand);
        }
        // TODO Option
        $dueDays = (int)self::Plugin()->oPluginEinstellungAssoc_arr[$this->moduleID . '_dueDays'];
        if ($dueDays > 3) {
            $paymentOptions['dueDate'] = date('Y-m-d', strtotime("+{$dueDays} DAYS"));
        }
        return $paymentOptions;
    }
}
