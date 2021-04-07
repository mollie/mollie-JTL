<?php

namespace ws_mollie\Checkout\Order;

use Shop;

class Address extends \ws_mollie\Checkout\Payment\Address
{

    /**
     * @var string|null
     */
    public $organizationName;

    /**
     * @var string|null
     */
    public $title;

    /**
     * @var string
     */
    public $givenName;

    /**
     * @var string
     */
    public $familyName;

    /**
     * @var string
     */
    public $email;

    /**
     * @var string|null
     */
    public $phone;

    public function __construct($adresse)
    {
        parent::__construct($adresse);

        $this->title = trim(($adresse->cAnrede === 'm' ? Shop::Lang()->get('mr') : Shop::Lang()->get('mrs')) . ' ' . $adresse->cTitel) ?: null;
        $this->givenName = $adresse->cVorname;
        $this->familyName = $adresse->cNachname;
        $this->email = $adresse->cMail ?: null;

        if ($organizationName = trim($adresse->cFirma)) {
            $this->organizationName = $organizationName;
        }
    }

}