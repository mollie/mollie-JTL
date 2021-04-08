<?php


namespace ws_mollie\Model;

use Exception;
use Kunde;
use Mollie\Api\Exceptions\ApiException;
use Session;
use stdClass;
use ws_mollie\API;
use ws_mollie\Checkout\Payment\Locale;
use ws_mollie\Helper;

/**
 * Class Customer
 * @package ws_mollie\Model
 *
 * @property int $kKunde
 * @property string $customerId
 */
class Customer extends AbstractModel
{

    const PRIMARY = 'kKunde';
    const TABLE = 'xplugin_ws_mollie_kunde';

    public static function createOrUpdate(Kunde $oKunde)
    {
        $mCustomer = self::fromID($oKunde->kKunde, self::PRIMARY);

        $api = new API(API::getMode());

        $customer = new stdClass();
        if (!$mCustomer->customerId) {
            if (!array_key_exists('mollie_create_customer', $_SESSION['cPost_arr']?:[]) || $_SESSION['cPost_arr']['mollie_create_customer'] !== 'on') {
                return null;
            }
        } else {
            try {
                $customer = $api->Client()->customers->get($mCustomer->customerId);
            } catch (ApiException $e) {
                Helper::logExc($e);
            }
        }


        $customer->name = trim($oKunde->cVorname . ' ' . $oKunde->cNachname);
        $customer->email = $oKunde->cMail;
        $customer->locale = Locale::getLocale(Session::getInstance()->Language()->getIso(), $oKunde->cLand);
        $customer->metadata = (object)[
            'kKunde' => $oKunde->kKunde,
            'kKundengruppe' => $oKunde->kKundengruppe,
            'cKundenNr' => $oKunde->cKundenNr,
        ];

        try {
            if ($customer instanceof \Mollie\Api\Resources\Customer) {
                $customer->update();
            } else {
                $customer = $api->Client()->customers->create((array)$customer);
                $mCustomer->customerId = $customer->id;
                $mCustomer->save();
            }
        } catch (Exception $e) {
            return null;
        }

        return $mCustomer->customerId;

    }
}