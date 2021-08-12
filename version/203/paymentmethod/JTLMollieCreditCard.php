<?php
/**
 * @copyright 2021 WebStollen GmbH
 * @link https://www.webstollen.de
 */

use ws_mollie\API;
use ws_mollie\Checkout\AbstractCheckout;
use ws_mollie\Checkout\Payment\Address;

require_once __DIR__ . '/JTLMollie.php';

class JTLMollieCreditCard extends JTLMollie
{
    const METHOD = \Mollie\Api\Types\PaymentMethod::CREDITCARD;

    const ALLOW_AUTO_STORNO          = true;
    const ALLOW_PAYMENT_BEFORE_ORDER = false;

    const CACHE_TOKEN           = 'creditcard:token';
    const CACHE_TOKEN_TIMESTAMP = 'creditcard:token:timestamp';

    public function preparePaymentProcess($order)
    {
        parent::preparePaymentProcess($order);
        $this->clearToken();
    }

    protected function clearToken()
    {
        $this->unsetCache(self::CACHE_TOKEN)
            ->unsetCache(self::CACHE_TOKEN_TIMESTAMP);

        return true;
    }

    public function handleAdditional($aPost_arr)
    {
        $components = self::Plugin()->oPluginEinstellungAssoc_arr[$this->moduleID . '_components'];
        $profileId  = self::Plugin()->oPluginEinstellungAssoc_arr['profileId'];

        if ($components === 'N' || !$profileId || trim($profileId) === '') {
            return parent::handleAdditional($aPost_arr);
        }

        $cleared = false;
        if (array_key_exists('clear', $aPost_arr) && (int)$aPost_arr['clear']) {
            $cleared = $this->clearToken();
        }

        if ($components === 'S' && array_key_exists('skip', $aPost_arr) && (int)$aPost_arr['skip']) {
            return parent::handleAdditional($aPost_arr);
        }

        try {
            $trustBadge   = (bool)self::Plugin()->oPluginEinstellungAssoc_arr[$this->moduleID . '_loadTrust'];
            $locale       = AbstractCheckout::getLocale($_SESSION['cISOSprache'], Session::getInstance()->Customer() ? Session::getInstance()->Customer()->cLand : null);
            $mode         = API::getMode();
            $errorMessage = json_encode(self::Plugin()->oPluginSprachvariableAssoc_arr['mcErrorMessage']);
        } catch (Exception $e) {
            Jtllog::writeLog($e->getMessage() . "\n" . print_r(['e' => $e], 1));

            return parent::handleAdditional($aPost_arr);
        }

        if (!$cleared && array_key_exists('cardToken', $aPost_arr) && ($token = trim($aPost_arr['cardToken']))) {
            return $this->setToken($token) && parent::handleAdditional($aPost_arr);
        }

        $token = false;
        if (($ctTS = (int)$this->getCache(self::CACHE_TOKEN_TIMESTAMP)) && $ctTS > time()) {
            $token = $this->getCache(self::CACHE_TOKEN);
        }

        Shop::Smarty()->assign('profileId', $profileId)
            ->assign('trustBadge', $trustBadge ? self::Plugin()->cFrontendPfadURLSSL . 'img/trust_' . $_SESSION['cISOSprache'] . '.png' : false)
            ->assign('components', $components)
            ->assign('locale', $locale ?: 'de_DE')
            ->assign('token', $token ?: false)
            ->assign('testMode', $mode ?: false)
            ->assign('errorMessage', $errorMessage ?: null)
            ->assign('mollieLang', self::Plugin()->oPluginSprachvariableAssoc_arr);

        return false;
    }

    protected function setToken($token)
    {
        $this->addCache(self::CACHE_TOKEN, $token)
            ->addCache(self::CACHE_TOKEN_TIMESTAMP, time() + 3600);

        return true;
    }

    public function getPaymentOptions(Bestellung $order, $apiType)
    {
        $paymentOptions = [];

        if ($apiType === 'payment') {
            if ($order->Lieferadresse !== null) {
                if (!$order->Lieferadresse->cMail) {
                    $order->Lieferadresse->cMail = $order->oRechnungsadresse->cMail;
                }
                $paymentOptions['shippingAddress'] = Address::factory($order->Lieferadresse);
            }

            $paymentOptions['billingAddress'] = Address::factory($order->oRechnungsadresse);
        }
        if ((int)$this->getCache(self::CACHE_TOKEN_TIMESTAMP) > time() && ($token = trim($this->getCache(self::CACHE_TOKEN)))) {
            $paymentOptions['cardToken'] = $token;
        }

        return $paymentOptions;
    }
}
