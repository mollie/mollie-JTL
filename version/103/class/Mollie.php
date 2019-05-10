<?php
/**
 * Created by PhpStorm.
 * User: proske
 * Date: 2019-01-11
 * Time: 09:55
 */

namespace ws_mollie;

use Bestellung;
use Exception;
use JTLMollie;
use Lieferschein;
use Lieferscheinpos;
use Mollie\Api\Resources\Order;
use Mollie\Api\Types\OrderStatus;
use Mollie\Api\Types\PaymentStatus;
use Shop;
use Shopsetting;
use stdClass;
use WarenkorbPos;
use ws_mollie\Model\Payment;

abstract class Mollie
{
    protected static $_jtlmollie;

    /**
     * @param $kBestellung
     * @param bool $redirect
     * @return bool|string
     */
    public static function getOrderCompletedRedirect($kBestellung, $redirect = true)
    {
        $mode = Shopsetting::getInstance()->getValue(CONF_KAUFABWICKLUNG, 'bestellabschluss_abschlussseite');

        $bestellid = Shop::DB()->select("tbestellid ", 'kBestellung', (int)$kBestellung);
        $url = Shop::getURL() . '/bestellabschluss.php?i=' . $bestellid->cId;


        if ($mode == 'S' || !$bestellid) { // Statusseite
            $bestellstatus = Shop::DB()->select('tbestellstatus', 'kBestellung', (int)$kBestellung);
            $url = Shop::getURL() . '/status.php?uid=' . $bestellstatus->cUID;
        }

        if ($redirect) {
            header('Location: ' . $url);
            exit();
        }
        return $url;
    }

    /**
     * @param Order $order
     * @param $kBestellung
     * @param bool $newStatus
     * @return array
     * @throws Exception
     */
    public static function getShipmentOptions(Order $order, $kBestellung, $newStatus = false)
    {
        if (!$order || !$kBestellung) {
            throw new Exception('Mollie::getShipmentOptions: order and kBestellung are required!');
        }


        $oBestellung = new Bestellung($kBestellung, true);
        if ($newStatus === false) {
            $newStatus = $oBestellung->cStatus;
        }
        $options = [];

        // Tracking Data
        if ($oBestellung->cTracking) {
            $tracking = new stdClass();
            $tracking->carrier = $oBestellung->cVersandartName;
            $tracking->url = $oBestellung->cTrackingURL;
            $tracking->code = $oBestellung->cTracking;
            $options['tracking'] = $tracking;
        }

        switch ((int)$newStatus) {
            case BESTELLUNG_STATUS_VERSANDT:
                $options['lines'] = [];
                break;
            case BESTELLUNG_STATUS_TEILVERSANDT:
                $lines = [];
                foreach ($order->lines as $i => $line) {
                    if (($quantity = Mollie::getBestellPosSent($line->sku, $oBestellung)) !== false && ($quantity - $line->quantityShipped) > 0) {
                        $x = $quantity - $line->quantityShipped;
                        $lines[] = (object)[
                            'id' => $line->id,
                            'quantity' => $x,
                            'amount' => (object)[
                                'currency' => $line->totalAmount->currency,
                                'value' => number_format($x * $line->unitPrice->value, 2),
                            ],
                        ];
                    }
                }
                if (count($lines)) {
                    $options['lines'] = $lines;
                }
                break;
            case BESTELLUNG_STATUS_STORNO:
                $options = null;
                break;
        }

        return $options;
    }

    /**
     * Returns amount of sent items for SKU
     * @param $sku
     * @param Bestellung $oBestellung
     * @return float|int
     * @throws Exception
     */
    public static function getBestellPosSent($sku, Bestellung $oBestellung)
    {
        if ($sku === null) {
            return 1;
        }
        /** @var WarenkorbPos $oPosition */
        foreach ($oBestellung->Positionen as $oPosition) {
            if ($oPosition->cArtNr === $sku) {
                $sent = 0;
                /** @var Lieferschein $oLieferschein */
                foreach ($oBestellung->oLieferschein_arr as $oLieferschein) {
                    /** @var Lieferscheinpos $oLieferscheinPos */
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

    /**
     * @param Order $order
     * @param null $kBestellung
     * @return bool
     * @throws Exception
     */
    public static function handleOrder(Order $order, $kBestellung)
    {
        $logData = '$' . $order->id . '#' . $kBestellung . "§" . $order->orderNumber;

        $oBestellung = new Bestellung($kBestellung);
        if ($oBestellung->kBestellung) {
            $order->orderNumber = $oBestellung->cBestellNr;
            Payment::updateFromPayment($order, $kBestellung);

            $oIncomingPayment = Shop::DB()->executeQueryPrepared("SELECT * FROM tzahlungseingang WHERE cHinweis = :cHinweis AND kBestellung = :kBestellung", [':cHinweis' => $order->id, ':kBestellung' => $oBestellung->kBestellung], 1);
            if (!$oIncomingPayment) {
                $oIncomingPayment = new stdClass();
            }

            // 2. Check PaymentStatus
            switch ($order->status) {
                case OrderStatus::STATUS_PAID:
                case OrderStatus::STATUS_COMPLETED:
                case OrderStatus::STATUS_AUTHORIZED:
                    $cHinweis = $order->id;
                    if ($payments = $order->payments()) {
                        /** @var \Mollie\Api\Resources\Payment $payment */
                        foreach ($payments as $payment) {
                            if (!in_array($payment->status, [PaymentStatus::STATUS_AUTHORIZED, PaymentStatus::STATUS_PAID])) {
                                $cHinweis .= ' / ' . $payment->status;
                            }
                        }
                    }
                    $oIncomingPayment->fBetrag = $order->amount->value;
                    $oIncomingPayment->cISO = $order->amount->currency;
                    $oIncomingPayment->cHinweis = $cHinweis;
                    Mollie::JTLMollie()->addIncomingPayment($oBestellung, $oIncomingPayment);
                    Mollie::JTLMollie()->setOrderStatusToPaid($oBestellung);
                    Mollie::JTLMollie()->doLog('PaymentStatus: ' . $order->status . ' => Zahlungseingang (' . $order->amount->value . ')', $logData, LOGLEVEL_DEBUG);
                    break;
                case OrderStatus::STATUS_SHIPPING:
                case OrderStatus::STATUS_PENDING:
                    Mollie::JTLMollie()->setOrderStatusToPaid($oBestellung);
                    Mollie::JTLMollie()->doLog('PaymentStatus: ' . $order->status . ' => Bestellung bezahlt, KEIN Zahlungseingang', $logData, LOGLEVEL_NOTICE);
                    break;
                case OrderStatus::STATUS_CANCELED:
                case OrderStatus::STATUS_EXPIRED:
                    Mollie::JTLMollie()->doLog('PaymentStatus: ' . $order->status, $logData, LOGLEVEL_ERROR);
                    break;
            }
            return true;
        }
        return false;
    }

    /**
     * @return JTLMollie
     * @throws Exception
     */
    public static function JTLMollie()
    {
        if (self::$_jtlmollie === null) {
            $pza = Shop::DB()->select('tpluginzahlungsartklasse', 'cClassName', 'JTLMollie');
            if (!$pza) {
                throw new Exception("Mollie Zahlungsart nicht in DB gefunden!");
            }
            require_once __DIR__ . '/../paymentmethod/JTLMollie.php';
            self::$_jtlmollie = new JTLMollie($pza->cModulId);
        }
        return self::$_jtlmollie;
    }

    public static function getLocales()
    {
        $locales = ['en_US',
            'nl_NL',
            'nl_BE',
            'fr_FR',
            'fr_BE',
            'de_DE',
            'de_AT',
            'de_CH',
            'es_ES',
            'ca_ES',
            'pt_PT',
            'it_IT',
            'nb_NO',
            'sv_SE',
            'fi_FI',
            'da_DK',
            'is_IS',
            'hu_HU',
            'pl_PL',
            'lv_LV',
            'lt_LT',];

        $laender = [];
        $shopLaender = Shop::DB()->executeQuery("SELECT cLaender FROM tversandart", 2);
        foreach ($shopLaender as $sL) {
            $laender = array_merge(explode(' ', $sL->cLaender));
        }
        $laender = array_unique($laender);

        $result = [];
        $shopSprachen = Shop::DB()->executeQuery("SELECT * FROM tsprache", 2);
        foreach ($shopSprachen as $sS) {
            foreach ($laender as $land) {
                $result[] = JTLMollie::getLocale($sS->cISO, $land);
            }
        }
        return array_unique($result);
    }

    public static function getCurrencies()
    {
        $currencies = ['AED' => 'AED - United Arab Emirates dirham',
            'AFN' => 'AFN - Afghan afghani',
            'ALL' => 'ALL - Albanian lek',
            'AMD' => 'AMD - Armenian dram',
            'ANG' => 'ANG - Netherlands Antillean guilder',
            'AOA' => 'AOA - Angolan kwanza',
            'ARS' => 'ARS - Argentine peso',
            'AUD' => 'AUD - Australian dollar',
            'AWG' => 'AWG - Aruban florin',
            'AZN' => 'AZN - Azerbaijani manat',
            'BAM' => 'BAM - Bosnia and Herzegovina convertible mark',
            'BBD' => 'BBD - Barbados dollar',
            'BDT' => 'BDT - Bangladeshi taka',
            'BGN' => 'BGN - Bulgarian lev',
            'BHD' => 'BHD - Bahraini dinar',
            'BIF' => 'BIF - Burundian franc',
            'BMD' => 'BMD - Bermudian dollar',
            'BND' => 'BND - Brunei dollar',
            'BOB' => 'BOB - Boliviano',
            'BRL' => 'BRL - Brazilian real',
            'BSD' => 'BSD - Bahamian dollar',
            'BTN' => 'BTN - Bhutanese ngultrum',
            'BWP' => 'BWP - Botswana pula',
            'BYN' => 'BYN - Belarusian ruble',
            'BZD' => 'BZD - Belize dollar',
            'CAD' => 'CAD - Canadian dollar',
            'CDF' => 'CDF - Congolese franc',
            'CHF' => 'CHF - Swiss franc',
            'CLP' => 'CLP - Chilean peso',
            'CNY' => 'CNY - Renminbi (Chinese) yuan',
            'COP' => 'COP - Colombian peso',
            'COU' => 'COU - Unidad de Valor Real (UVR)',
            'CRC' => 'CRC - Costa Rican colon',
            'CUC' => 'CUC - Cuban convertible peso',
            'CUP' => 'CUP - Cuban peso',
            'CVE' => 'CVE - Cape Verde escudo',
            'CZK' => 'CZK - Czech koruna',
            'DJF' => 'DJF - Djiboutian franc',
            'DKK' => 'DKK - Danish krone',
            'DOP' => 'DOP - Dominican peso',
            'DZD' => 'DZD - Algerian dinar',
            'EGP' => 'EGP - Egyptian pound',
            'ERN' => 'ERN - Eritrean nakfa',
            'ETB' => 'ETB - Ethiopian birr',
            'EUR' => 'EUR - Euro',
            'FJD' => 'FJD - Fiji dollar',
            'FKP' => 'FKP - Falkland Islands pound',
            'GBP' => 'GBP - Pound sterling',
            'GEL' => 'GEL - Georgian lari',
            'GHS' => 'GHS - Ghanaian cedi',
            'GIP' => 'GIP - Gibraltar pound',
            'GMD' => 'GMD - Gambian dalasi',
            'GNF' => 'GNF - Guinean franc',
            'GTQ' => 'GTQ - Guatemalan quetzal',
            'GYD' => 'GYD - Guyanese dollar',
            'HKD' => 'HKD - Hong Kong dollar',
            'HNL' => 'HNL - Honduran lempira',
            'HRK' => 'HRK - Croatian kuna',
            'HTG' => 'HTG - Haitian gourde',
            'HUF' => 'HUF - Hungarian forint',
            'IDR' => 'IDR - Indonesian rupiah',
            'ILS' => 'ILS - Israeli new shekel',
            'INR' => 'INR - Indian rupee',
            'IQD' => 'IQD - Iraqi dinar',
            'IRR' => 'IRR - Iranian rial',
            'ISK' => 'ISK - Icelandic króna',
            'JMD' => 'JMD - Jamaican dollar',
            'JOD' => 'JOD - Jordanian dinar',
            'JPY' => 'JPY - Japanese yen',
            'KES' => 'KES - Kenyan shilling',
            'KGS' => 'KGS - Kyrgyzstani som',
            'KHR' => 'KHR - Cambodian riel',
            'KMF' => 'KMF - Comoro franc',
            'KPW' => 'KPW - North Korean won',
            'KRW' => 'KRW - South Korean won',
            'KWD' => 'KWD - Kuwaiti dinar',
            'KYD' => 'KYD - Cayman Islands dollar',
            'KZT' => 'KZT - Kazakhstani tenge',
            'LAK' => 'LAK - Lao kip',
            'LBP' => 'LBP - Lebanese pound',
            'LKR' => 'LKR - Sri Lankan rupee',
            'LRD' => 'LRD - Liberian dollar',
            'LSL' => 'LSL - Lesotho loti',
            'LYD' => 'LYD - Libyan dinar',
            'MAD' => 'MAD - Moroccan dirham',
            'MDL' => 'MDL - Moldovan leu',
            'MGA' => 'MGA - Malagasy ariary',
            'MKD' => 'MKD - Macedonian denar',
            'MMK' => 'MMK - Myanmar kyat',
            'MNT' => 'MNT - Mongolian tögrög',
            'MOP' => 'MOP - Macanese pataca',
            'MRU' => 'MRU - Mauritanian ouguiya',
            'MUR' => 'MUR - Mauritian rupee',
            'MVR' => 'MVR - Maldivian rufiyaa',
            'MWK' => 'MWK - Malawian kwacha',
            'MXN' => 'MXN - Mexican peso',
            'MXV' => 'MXV - Mexican Unidad de Inversion (UDI)',
            'MYR' => 'MYR - Malaysian ringgit',
            'MZN' => 'MZN - Mozambican metical',
            'NAD' => 'NAD - Namibian dollar',
            'NGN' => 'NGN - Nigerian naira',
            'NIO' => 'NIO - Nicaraguan córdoba',
            'NOK' => 'NOK - Norwegian krone',
            'NPR' => 'NPR - Nepalese rupee',
            'NZD' => 'NZD - New Zealand dollar',
            'OMR' => 'OMR - Omani rial',
            'PAB' => 'PAB - Panamanian balboa',
            'PEN' => 'PEN - Peruvian sol',
            'PGK' => 'PGK - Papua New Guinean kina',
            'PHP' => 'PHP - Philippine peso',
            'PKR' => 'PKR - Pakistani rupee',
            'PLN' => 'PLN - Polish z?oty',
            'PYG' => 'PYG - Paraguayan guaraní',
            'QAR' => 'QAR - Qatari riyal',
            'RON' => 'RON - Romanian leu',
            'RSD' => 'RSD - Serbian dinar',
            'RUB' => 'RUB - Russian ruble',
            'RWF' => 'RWF - Rwandan franc',
            'SAR' => 'SAR - Saudi riyal',
            'SBD' => 'SBD - Solomon Islands dollar',
            'SCR' => 'SCR - Seychelles rupee',
            'SDG' => 'SDG - Sudanese pound',
            'SEK' => 'SEK - Swedish krona/kronor',
            'SGD' => 'SGD - Singapore dollar',
            'SHP' => 'SHP - Saint Helena pound',
            'SLL' => 'SLL - Sierra Leonean leone',
            'SOS' => 'SOS - Somali shilling',
            'SRD' => 'SRD - Surinamese dollar',
            'SSP' => 'SSP - South Sudanese pound',
            'STN' => 'STN - São Tomé and Príncipe dobra',
            'SVC' => 'SVC - Salvadoran colón',
            'SYP' => 'SYP - Syrian pound',
            'SZL' => 'SZL - Swazi lilangeni',
            'THB' => 'THB - Thai baht',
            'TJS' => 'TJS - Tajikistani somoni',
            'TMT' => 'TMT - Turkmenistan manat',
            'TND' => 'TND - Tunisian dinar',
            'TOP' => 'TOP - Tongan pa?anga',
            'TRY' => 'TRY - Turkish lira',
            'TTD' => 'TTD - Trinidad and Tobago dollar',
            'TWD' => 'TWD - New Taiwan dollar',
            'TZS' => 'TZS - Tanzanian shilling',
            'UAH' => 'UAH - Ukrainian hryvnia',
            'UGX' => 'UGX - Ugandan shilling',
            'USD' => 'USD - United States dollar',
            'UYI' => 'UYI - Uruguay Peso en Unidades Indexadas',
            'UYU' => 'UYU - Uruguayan peso',
            'UYW' => 'UYW - Unidad previsional',
            'UZS' => 'UZS - Uzbekistan som',
            'VES' => 'VES - Venezuelan bolívar soberano',
            'VND' => 'VND - Vietnamese ??ng',
            'VUV' => 'VUV - Vanuatu vatu',
            'WST' => 'WST - Samoan tala',
            'YER' => 'YER - Yemeni rial',
            'ZAR' => 'ZAR - South African rand',
            'ZMW' => 'ZMW - Zambian kwacha',
            'ZWL' => 'ZWL - Zimbabwean dollar'];

        $shopCurrencies = Shop::DB()->executeQuery("SELECT * FROM twaehrung", 2);

        $result = [];

        foreach ($shopCurrencies as $sC) {
            if (array_key_exists($sC->cISO, $currencies)) {
                $result[$sC->cISO] = $currencies[$sC->cISO];
            }
        }

        return $result;
    }
}
