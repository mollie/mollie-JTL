<?php
/**
 * Created by PhpStorm.
 * User: proske
 * Date: 2019-01-11
 * Time: 09:55
 */

namespace ws_mollie;


abstract class Mollie
{

    /**
     * @param $kBestellung
     * @param bool $redirect
     * @return bool|string
     */
    public static function getOrderCompletedRedirect($kBestellung, $redirect = true)
    {
        $mode = \Shopsetting::getInstance()->getValue(CONF_KAUFABWICKLUNG, 'bestellabschluss_abschlussseite');
        if ($mode == 'S') { // Statusseite

            $bestellstatus = \Shop::DB()->select('tbestellstatus', 'kBestellung', (int)$kBestellung);
            $url = \Shop::getURL() . '/status.php?uid=' . $bestellstatus->cUID;

        } else { // Abschlussseite
            $bestellid = \Shop::DB()->select("tbestellid ", 'kBestellung', (int)$kBestellung);
            $url = \Shop::getURL() . '/bestellabschluss.php?i=' . $bestellid->cId;
        }

        if ($redirect) {
            header('Location: ' . $url);
            exit();
        }

        return $url;


    }

}