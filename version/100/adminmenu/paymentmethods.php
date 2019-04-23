<?php

use Mollie\Api\MollieApiClient;
use ws_mollie\Helper;

require_once __DIR__ . '/../class/Helper.php';
try {
    if (!Helper::init()) {
        echo "Kein gültige Lizenz?";
        return;
    }

    global $oPlugin;


    $mollie = new MollieApiClient();
    $mollie->setApiKey(Helper::getSetting("api_key"));

    $profile = $mollie->profiles->get('me');
    $methods = $mollie->methods->allAvailable([
        'locale' => 'de_DE',
        'include' => 'pricing',
    ]);

    Shop::Smarty()->assign('methods', $methods)
        ->assign('profile', $profile);
    Shop::Smarty()->display($oPlugin->cAdminmenuPfad . '/tpl/paymentmethods.tpl');
} catch (Exception $e) {
    echo "<div class='alert alert-danger'>{$e->getMessage()}</div>";
    Helper::logExc($e);
}
