<?php

use ws_mollie\Helper;

require_once __DIR__ . '/../class/Helper.php';
try {
    Helper::init();

    if (array_key_exists("action", $_REQUEST) && $_REQUEST['action'] === 'update-plugin') {
        Shop::Smarty()->assign('defaultTabbertab', Helper::getAdminmenu('Info'));
        Helper::selfupdate();
    }

    $svgQuery = http_build_query([
        'p' => Helper::oPlugin()->cPluginID,
        'v' => Helper::oPlugin()->nVersion,
        's' => defined('APPLICATION_VERSION') ? APPLICATION_VERSION : JTL_VERSION,
        'd' => Helper::getDomain(),
        'php' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION,
    ]);

    echo "<script type='application/javascript' src='//cdn.webstollen.com/plugin/js/ws.js?p=" . Helper::oPlugin()->cPluginID . '&v=' . Helper::oPlugin()->nVersion . "'></script>";
    echo "<div id='ws-head-bar' class='row'>" .
        "  <div class='col-md-4 text-center'>" .
        "    <object data='//lic.dash.bar/info/licence?{$svgQuery}' type='image/svg+xml'>" .
        "      <img src='//lic.dash.bar/info/licence.png?{$svgQuery}' width='370' height='20' alt='Lizenz Informationen'>" .
        '    </object>' .
        '  </div>' .
        "  <div class='col-md-4 text-center'>" .
        "    <object data='//lic.dash.bar/info/version?{$svgQuery}' type='image/svg+xml'>" .
        "      <img src='//lic.dash.bar/info/version.png?{$svgQuery}' width='370' height='20' alt='Update Informationen'>" .
        '    </object>' .
        '  </div>' .
        "  <div class='col-md-4 text-center'>" .
        "    <object data='//lic.dash.bar/info/help?{$svgQuery}' type='image/svg+xml'>" .
        "      <img src='//lic.dash.bar/info/help.png?{$svgQuery}' width='370' height='20' alt='Plugin informationen'>" .
        '    </object>' .
        '  </div>' .
        '</div>';

    try {
        $latestRelease = Helper::getLatestRelease(array_key_exists('update', $_REQUEST));
        if ((int)Helper::oPlugin()->nVersion < (int)$latestRelease->version) {
            Shop::Smarty()->assign('update', $latestRelease);
        }

    } catch (Exception $e) {
    }

    Shop::Smarty()->display(Helper::oPlugin()->cAdminmenuPfad . '/tpl/info.tpl');
} catch (Exception $e) {
    echo "<div class='alert alert-danger'>Fehler: {$e->getMessage()}</div>";
    Helper::logExc($e);
}