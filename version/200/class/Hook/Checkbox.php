<?php


namespace ws_mollie\Hook;


use Session;
use ws_mollie\Model\Customer;

class Checkbox extends AbstractHook
{
    public static function execute(&$args_arr)
    {

        if (!array_key_exists('Zahlungsart', $_SESSION) || !$_SESSION['Zahlungsart'] || strpos($_SESSION['Zahlungsart']->cModulId, 'kPlugin_' . self::Plugin()->kPlugin . '_') === false) {
            return;
        }

        if (array_key_exists('nAnzeigeOrt', $args_arr) && $args_arr['nAnzeigeOrt'] === CHECKBOX_ORT_BESTELLABSCHLUSS && (int)Session::getInstance()->Customer()->nRegistriert) {

            $mCustomer = Customer::fromID(Session::getInstance()->Customer()->kKunde, 'kKunde');

            if ($mCustomer->customerId) {
                return;
            }

            $checkbox = new \CheckBox();
            $checkbox->kLink = 0;
            $checkbox->kCheckBox = -1;
            $checkbox->kCheckBoxFunktion = 0;
            $checkbox->cName = 'MOLLIE SAVE CUSTOMER';
            $checkbox->cKundengruppe = ';1;';
            $checkbox->cAnzeigeOrt = ';2;';
            $checkbox->nAktiv = 1;
            $checkbox->nPflicht = 0;
            $checkbox->nLogging = 0;
            $checkbox->nSort = 999;
            $checkbox->dErstellt = date('Y-m-d H:i:s');
            $checkbox->oCheckBoxSprache_arr = [];

            $langs = gibAlleSprachen();
            foreach ($langs as $lang) {
                $checkbox->oCheckBoxSprache_arr[$lang->kSprache] = (object)[
                    'cText' => self::Plugin()->oPluginSprachvariableAssoc_arr['checkboxText'],
                    'cBeschreibung' => self::Plugin()->oPluginSprachvariableAssoc_arr['checkboxDescr'],
                    'kSprache' => $lang->kSprache,
                    'kCheckbox' => -1
                ];
            }

            $checkbox->kKundengruppe_arr = [Session::getInstance()->Customer()->kKundengruppe];
            $checkbox->kAnzeigeOrt_arr = [CHECKBOX_ORT_BESTELLABSCHLUSS];
            $checkbox->cID = "mollie_create_customer";
            $checkbox->cLink = '';

            $args_arr['oCheckBox_arr'][] = $checkbox;

        }
    }
}