<?php

use ws_mollie\Helper;

require_once __DIR__ . '/JTLMollie.php';

class JTLMollieCreditCard extends JTLMollie
{
    const MOLLIE_METHOD = \Mollie\Api\Types\PaymentMethod::CREDITCARD;


    public function handleAdditional($aPost_arr)
    {
        $profileId = trim(Helper::getSetting('profileId'));
        if ($profileId === '' || strpos($profileId, 'pfl_') !== 0) {
            return true;
        }
        if (array_key_exists('mollieCardTokenTS', $_SESSION) && (int)$_SESSION['mollieCardTokenTS'] > time()
            && array_key_exists('mollieCardToken', $_SESSION) && trim($_SESSION['mollieCardToken']) !== '') {
            return true;
        }

        unset($_SESSION['mollieCardToken']);
        unset($_SESSION['mollieCardTokenTS']);

        if (array_key_exists('cardToken', $aPost_arr) && trim($aPost_arr['cardToken'])) {
            $_SESSION['mollieCardToken'] = trim($aPost_arr['cardToken']);
            $_SESSION['mollieCardTokenTS'] = time() + 3600;
            return true;
        }

        Shop::Smarty()->assign('profileId', $profileId)
            ->assign('locale', self::getLocale($_SESSION['cISOSprache'], $_SESSION['Kunde']->cLand))
            ->assign('testmode', strpos(trim(Helper::getSetting('api_key')), 'test_') === 0)
            ->assign('mollieLang', Helper::oPlugin()->oPluginSprachvariableAssoc_arr)
            ->assign('trustBadge', Helper::getSetting('loadTrust') === 'Y' ? Helper::oPlugin()->cFrontendPfadURLSSL . 'img/trust_' . $_SESSION['cISOSprache'] . '.png' : false);

        return false;
    }


}
