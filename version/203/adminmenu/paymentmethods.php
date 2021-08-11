<?php
/**
 * @copyright 2021 WebStollen GmbH
 * @link https://www.webstollen.de
 */

use Mollie\Api\MollieApiClient;
use Mollie\Api\Resources\Method;
use ws_mollie\Checkout\AbstractCheckout;
use ws_mollie\Helper;
use ws_mollie\Mollie;

require_once __DIR__ . '/../class/Helper.php';

global $oPlugin;

try {
    if (!Helper::init()) {
        echo 'Kein gültige Lizenz?';

        return;
    }

    $mollie = new MollieApiClient();
    $mollie->setApiKey(Helper::getSetting('api_key'));

    $profile = $mollie->profiles->get('me');

    $za       = filter_input(INPUT_GET, 'za', FILTER_VALIDATE_BOOLEAN);
    $active   = filter_input(INPUT_GET, 'active', FILTER_VALIDATE_BOOLEAN);
    $amount   = filter_input(INPUT_GET, 'amount', FILTER_VALIDATE_FLOAT) ?: null;
    $locale   = filter_input(INPUT_GET, 'locale', FILTER_VALIDATE_REGEXP, ['options' => ['regexp' => '/^[a-zA-Z]{2}_[a-zA-Z]{2}$/']]) ?: AbstractCheckout::getLocale();
    $currency = filter_input(INPUT_GET, 'currency', FILTER_VALIDATE_REGEXP, ['options' => ['regexp' => '/^[a-zA-Z]{3}$/']]) ?: 'EUR';


    if ($za) {
        Shop::Smarty()->assign('defaultTabbertab', Helper::getAdminmenu('Zahlungsarten'));
    }

    $params = [
        'include' => 'pricing,issuers',
        'locale'  => $locale
    ];
    if ($amount && $currency) {
        $params['amount'] = ['value' => number_format($amount, 2, '.', ''), 'currency' => $currency];
        if ($active) {
            $params['includeWallets'] = 'applepay';
            //$params['resource'] = 'orders';
        }
    }

    $allMethods = [];
    if ($active) {
        $_allMethods = $mollie->methods->allActive($params);
    } else {
        $_allMethods = $mollie->methods->allAvailable($params);
    }

    $sessionLife = (int)ini_get('session.gc_maxlifetime');

    /** @var Method $method */
    foreach ($_allMethods as $method) {
        $id  = $method->id === 'creditcard' ? 'kreditkarte' : $method->id;
        $key = "kPlugin_{$oPlugin->kPlugin}_mollie$id";

        $class  = null;
        $shop   = null;
        $oClass = null;

        if (array_key_exists($key, $oPlugin->oPluginZahlungsKlasseAssoc_arr) && !in_array($id, ['voucher', 'giftcard', 'directdebit'], true)) {
            $class = $oPlugin->oPluginZahlungsKlasseAssoc_arr[$key];
            include_once $oPlugin->cPluginPfad . 'paymentmethod/' . $class->cClassPfad;
            /** @var JTLMollie $oClass */
            $oClass = new $class->cClassName($id);
        }
        if (array_key_exists($key, $oPlugin->oPluginZahlungsmethodeAssoc_arr)) {
            $shop = $oPlugin->oPluginZahlungsmethodeAssoc_arr[$key];
        }

        $maxExpiryDays           = $oClass ? $oClass->getExpiryDays() : null;
        $allMethods[$method->id] = (object)[
            'mollie'          => $method,
            'class'           => $class,
            'allowPreOrder'   => $oClass ? $oClass::ALLOW_PAYMENT_BEFORE_ORDER : false,
            'allowAutoStorno' => $oClass ? $oClass::ALLOW_AUTO_STORNO : false,
            'oClass'          => $oClass,
            'shop'            => $shop,
            'maxExpiryDays'   => $oClass ? $maxExpiryDays : null,
            'warning'         => $oClass && ($maxExpiryDays * 24 * 60 * 60) > $sessionLife,
            'session'         => round($sessionLife / 60 / 60, 2) . 'h'
        ];
    }

    Shop::Smarty()->assign('profile', $profile)
        ->assign('currencies', Mollie::getCurrencies())
        ->assign('locales', Mollie::getLocales())
        ->assign('allMethods', $allMethods)
        ->assign('settings', $oPlugin->oPluginEinstellungAssoc_arr);
    Shop::Smarty()->display($oPlugin->cAdminmenuPfad . '/tpl/paymentmethods.tpl');
} catch (Exception $e) {
    echo "<div class='alert alert-danger'>{$e->getMessage()}</div>";
    Helper::logExc($e);
}
