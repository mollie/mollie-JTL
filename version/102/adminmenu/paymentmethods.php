<?php

use ws_mollie\Helper;

require_once __DIR__ . '/../class/Helper.php';
try {
    if (!Helper::init()) {
        echo "Kein gültige Lizenz?";
        return;
    }

    global $oPlugin;


    $mollie = new \Mollie\Api\MollieApiClient();
    $mollie->setApiKey(Helper::getSetting("api_key"));

    $profile = $mollie->profiles->get('me');
    /*    $methods = $mollie->methods->all([
            //'locale' => 'de_DE',
            'include' => 'pricing',
        ]);*/

    $za = filter_input(INPUT_GET, 'za', FILTER_VALIDATE_BOOLEAN);
    $active = filter_input(INPUT_GET, 'active', FILTER_VALIDATE_BOOLEAN);
    $amount = filter_input(INPUT_GET, 'amount', FILTER_VALIDATE_FLOAT) ?: null;
    $locale = filter_input(INPUT_GET, 'locale', FILTER_VALIDATE_REGEXP, ['options' => ['regexp' => '/^[a-zA-Z]{2}_[a-zA-Z]{2}$/']]) ?: null;
    $currency = filter_input(INPUT_GET, 'currency', FILTER_VALIDATE_REGEXP, ['options' => ['regexp' => '/^[a-zA-Z]{3}$/']]) ?: 'EUR';

    if ($za) {
        Shop::Smarty()->assign('defaultTabbertab', Helper::getAdminmenu("Zahlungsarten"));
    }

    $params = ['include' => 'pricing,issuers'];
    if ($amount && $currency && $locale) {
        $params['amount'] = ['value' => number_format($amount, 2, '.', ''), 'currency' => $currency];
        $params['locale'] = $locale;
    }

    if ($active) {
        $allMethods = $mollie->methods->allActive($params);
    } else {
        $allMethods = $mollie->methods->allAvailable($params);
    }

    Shop::Smarty()->assign('profile', $profile)
        ->assign('currencies', \ws_mollie\Mollie::getCurrencies())
        ->assign('locales', \ws_mollie\Mollie::getLocales())
        ->assign('allMethods', $allMethods);
    Shop::Smarty()->display($oPlugin->cAdminmenuPfad . '/tpl/paymentmethods.tpl');
} catch (Exception $e) {
    echo "<div class='alert alert-danger'>{$e->getMessage()}</div>";
    Helper::logExc($e);
}
