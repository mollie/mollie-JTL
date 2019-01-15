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

    protected static $_jtlmollie;

    public static function JTLMollie()
    {
        if (self::$_jtlmollie === null) {
            $pza = \Shop::DB()->select('tpluginzahlungsartklasse', 'cClassName', 'JTLMollie');
            if (!$pza) {
                throw new \Exception("Mollie Zahlungsart nicht in DB gefunden!");
            }
            require_once __DIR__ . '/../paymentmethod/JTLMollie.php';
            self::$_jtlmollie = new \JTLMollie($pza->cModulId);
        }
        return self::$_jtlmollie;
    }


    /**
     * Returns amount of sent items for SKU
     * @param $sku
     * @param \Bestellung $oBestellung
     * @return float|int
     * @throws \Exception
     */
    public static function getBestellPosSent($sku, \Bestellung $oBestellung)
    {
        if ($sku === null) {
            return 1;
        }
        /** @var \WarenkorbPos $oPosition */
        foreach ($oBestellung->Positionen as $oPosition) {
            if ($oPosition->cArtNr === $sku) {
                $sent = 0;
                /** @var \Lieferschein $oLieferschein */
                foreach ($oBestellung->oLieferschein_arr as $oLieferschein) {
                    /** @var \Lieferscheinpos $oLieferscheinPos */
                    foreach ($oLieferschein->oLieferscheinPos_arr as $oLieferscheinPos) {
                        if ($oLieferscheinPos->getBestellPos() == $oPosition->kBestellpos) {
                            $sent += $oLieferscheinPos->getAnzahl();
                        }
                    }
                }
                return $sent;
            }
        }
        return false;
    }


}