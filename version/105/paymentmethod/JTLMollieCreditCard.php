<?php

use ws_mollie\Helper;

require_once __DIR__ . '/JTLMollie.php';

class JTLMollieCreditCard extends JTLMollie
{
    const MOLLIE_METHOD = \Mollie\Api\Types\PaymentMethod::CREDITCARD;


    public function handleAdditional($aPost_arr)
    {

        if (trim(Helper::getSetting('profileId')) === '') {
            return true;
        }

        unset($_SESSION['mollieCardToken']);

        if (array_key_exists('cardToken', $aPost_arr) && trim($aPost_arr['cardToken'])) {
            $_SESSION['mollieCardToken'] = trim($aPost_arr['cardToken']);
            return true;
        }

        Shop::Smarty()->assign('profileId', trim(Helper::getSetting('profileId')))
            ->assign('locale', self::getLocale($_SESSION['cISOSprache'], $_SESSION['Kunde']->cLand))
            ->assign('testmode', strpos(Helper::getSetting('api_key'), 'test_') === 0)
            ->assign('mollieLang', Helper::oPlugin()->oPluginSprachvariableAssoc_arr);


        return false;
    }


}
