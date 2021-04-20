<?php


namespace ws_mollie\Hook;


use Shop;
use SmartyException;

class ApplePay extends AbstractHook
{

    /**
     * @param array $args_arr
     * @throws SmartyException
     */
    public static function execute($args_arr = [])
    {

        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            return;
        }

        // Reset CreditCard-Token after Order!
        if (($key = sprintf("kPlugin_%d_creditcard", self::Plugin()->kPlugin))
            && array_key_exists($key, $_SESSION) && !array_key_exists("Zahlungsart", $_SESSION)) {
            unset($_SESSION[$key]);
        }

        if (!array_key_exists('ws_mollie_applepay_available', $_SESSION)) {
            Shop::Smarty()->assign('applePayCheckURL', json_encode(self::Plugin()->cFrontendPfadURLSSL . 'applepay.php'));
            pq('body')->append(Shop::Smarty()->fetch(self::Plugin()->cFrontendPfad . 'tpl/applepay.tpl'));
        }

    }

    /**
     * @return bool
     */
    public static function isAvailable()
    {
        if (array_key_exists('ws_mollie_applepay_available', $_SESSION)) {
            return $_SESSION['ws_mollie_applepay_available'];
        }
        return false;
    }

    /**
     * @param $status bool
     */
    public static function setAvailable($status)
    {
        $_SESSION['ws_mollie_applepay_available'] = $status;
    }

}