<?php

require_once __DIR__ . '/../class/Helper.php';
try {
    if (!\ws_mollie\Helper::init()) {
        echo "Kein gültige Lizenz?";
        return;
    }

    global $oPlugin;


    $mollie = new \Mollie\Api\MollieApiClient();
    $mollie->setApiKey(\ws_mollie\Helper::getSetting("api_key"));

    $profile = $mollie->profiles->get('me');
    $methods = $mollie->methods->all([
        'locale' => 'de_DE',
        'include' => 'pricing',
    ]);

    Shop::Smarty()->assign('methods', $methods)
        ->assign('profile', $profile);
    Shop::Smarty()->display($oPlugin->cAdminmenuPfad . '/tpl/paymentmethods.tpl');
} catch (Exception $e) {
    echo "<div class='alert alert-danger'>{$e->getMessage()}</div>";
    \ws_mollie\Helper::logExc($e);
}
