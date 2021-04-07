<?php


namespace ws_mollie\Checkout\Payment;


use stdClass;
use ws_mollie\Traits\Jsonable;

class Address implements \JsonSerializable
{

    use Jsonable;

    /**
     * @var string
     */
    public $streetAndNumber;

    /**
     * @var string|null
     */
    public $streetAdditional;

    /**
     * @var string
     */
    public $postalCode;

    /**
     * @var string
     */
    public $city;

    /**
     * @var string|null
     */
    public $region;

    /**
     * @var string
     */
    public $country;

    /**
     * @param stdClass|\Lieferadresse|\Rechnungsadresse $adresse
     * @return Address
     */
    public function __construct($adresse)
    {

        $this->streetAndNumber = $adresse->cStrasse . ' ' . $adresse->cHausnummer;
        $this->postalCode = $adresse->cPLZ;
        $this->city = $adresse->cOrt;
        $this->country = $adresse->cLand;

        if (
            isset($adresse->cAdressZusatz)
            && trim($adresse->cAdressZusatz) !== ''
        ) {
            $this->streetAdditional = trim($adresse->cAdressZusatz);
        }

    }
}