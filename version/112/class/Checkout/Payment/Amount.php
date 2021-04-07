<?php


namespace ws_mollie\Checkout\Payment;


use Shop;

class Amount implements \JsonSerializable
{

    protected $data = [];

    public function value(){
        return $this->data['value'];
    }

    public function currency(){
        return $this->data['currency'];
    }

    /**
     * @var object
     */
    protected $currency;

    public function __construct($value, $currency = null, $useFactor = true, $useRounding = false)
    {
        $this->currency = $currency;
        if (!$currency) {
            $this->waehrung = isset($_SESSION['Waehrung']) ? $_SESSION['Waehrung'] : null;
        }
        if (!$currency) {
            $this->currency = Shop::DB()->select('twaehrung', 'cStandard', 'Y');
        }

        if ($useFactor) {
            $value *= $currency->fFaktor;
        }
        if ($useRounding) {
            $value = $this->optionaleRundung($value);
        }
        $this->data['value'] = number_format($value, 2, '.', '');

        $this->data['currency'] = $currency->cISO;
    }

    public function optionaleRundung($gesamtsumme)
    {
        $conf = \Shop::getSettings([CONF_KAUFABWICKLUNG]);
        if (isset($conf['kaufabwicklung']['bestellabschluss_runden5']) && $conf['kaufabwicklung']['bestellabschluss_runden5'] == 1) {
            $faktor = $this->currency->fFaktor;
            $gesamtsumme *= $faktor;

            // simplification. see https://de.wikipedia.org/wiki/Rundung#Rappenrundung
            $gesamtsumme = round($gesamtsumme * 20) / 20;
            $gesamtsumme /= $faktor;
        }

        return $gesamtsumme;
    }

    public function jsonSerialize()
    {
        return $this->data;
    }

}