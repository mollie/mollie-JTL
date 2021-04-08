<?php
/** @noinspection PhpDeprecationInspection */
/** @noinspection PhpConditionAlreadyCheckedInspection */

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
use Mollie\Api\Types\PaymentMethod;
use Mollie\Api\Types\PaymentStatus;
use Shop;
use Shopsetting;
use stdClass;
use Versand;
use ws_mollie\Checkout\Payment\Locale;
use ws_mollie\Model\Payment;

/**
 * Class Mollie
 * @package ws_mollie
 * @deprecated
 */
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


        if ($mode === 'S' || !$bestellid) { // Statusseite
            $bestellstatus = Shop::DB()->select('tbestellstatus', 'kBestellung', (int)$kBestellung);
            $url = Shop::getURL() . '/status.php?uid=' . $bestellstatus->cUID;
        }

        if ($redirect) {
            if (!headers_sent()) {
                header('Location: ' . $url);
            }
            echo "<a href='{$url}'>redirect ...</a>";
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
            $newStatus = (int)$oBestellung->cStatus;
        }
        $options = [];

        // Tracking Data
        if (isset($oBestellung->oLieferschein_arr)) {
            $nLS = count($oBestellung->oLieferschein_arr) - 1;
            if (isset($oBestellung->oLieferschein_arr[$nLS], $oBestellung->oLieferschein_arr[$nLS]->oVersand_arr) && $nLS >= 0) {
                $nV = count($oBestellung->oLieferschein_arr[$nLS]->oVersand_arr) - 1;
                if ($nV >= 0 && isset($oBestellung->oLieferschein_arr[$nLS]->oVersand_arr[$nV])) {
                    /** @var Versand $oVersand */
                    $oVersand = $oBestellung->oLieferschein_arr[$nLS]->oVersand_arr[$nV];
                    $tracking = new stdClass();
                    $tracking->carrier = utf8_encode(trim($oVersand->getLogistik()));
                    $tracking->url = utf8_encode(trim($oVersand->getLogistikURL()));
                    $tracking->code = utf8_encode(trim($oVersand->getIdentCode()));
                    if ($tracking->code && $tracking->carrier) {
                        $options['tracking'] = $tracking;
                    }
                }
            }
        }

        $logData = '#' . $oBestellung->kBestellung . '§' . $oBestellung->cBestellNr . '$' . $order->id;

        switch ($newStatus) {
            case BESTELLUNG_STATUS_VERSANDT:
                Mollie::JTLMollie()->doLog('181_sync: Bestellung versandt', $logData, LOGLEVEL_DEBUG);
                $options['lines'] = [];
                break;
            case BESTELLUNG_STATUS_TEILVERSANDT:
                $lines = [];
                foreach ($order->lines as $i => $line) {
                    if ($line->totalAmount->value > 0.0)
                        if (($quantity = Mollie::getBestellPosSent($line->sku, $oBestellung)) !== false && ($quantity - $line->quantityShipped) > 0) {
                            $x = min($quantity - $line->quantityShipped, $line->shippableQuantity);
                            if ($x > 0) {
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
                }
                Mollie::JTLMollie()->doLog('181_sync: Bestellung teilversandt', $logData, LOGLEVEL_DEBUG);
                if (count($lines)) {
                    $options['lines'] = $lines;
                }
                break;
            case BESTELLUNG_STATUS_STORNO:
                Mollie::JTLMollie()->doLog('181_sync: Bestellung storniert', $logData, LOGLEVEL_DEBUG);
                $options = null;
                break;
            case BESTELLUNG_STATUS_BEZAHLT:
            case BESTELLUNG_STATUS_IN_BEARBEITUNG:
            case BESTELLUNG_STATUS_OFFEN:
                // NOTHING TO DO!
                break;
            default:
                Mollie::JTLMollie()->doLog('181_sync: Bestellungstatus unbekannt: ' . $newStatus . '/' . $oBestellung->cStatus, $logData, LOGLEVEL_DEBUG);
        }

        return $options;
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
        foreach ($oBestellung->Positionen as $oPosition) {
            if ($oPosition->cArtNr === $sku) {
                $sent = 0;
                /** @var Lieferschein $oLieferschein */
                foreach ($oBestellung->oLieferschein_arr as $oLieferschein) {
                    /** @var Lieferscheinpos $oLieferscheinPos */
                    foreach ($oLieferschein->oLieferscheinPos_arr as $oLieferscheinPos) {
                        if ((int)$oLieferscheinPos->getBestellPos() === (int)$oPosition->kBestellpos) {
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
     *
     */
    public static function fixZahlungsarten()
    {
        $kPlugin = Helper::oPlugin()->kPlugin;
        if ((int)$kPlugin) {
            $test1 = 'kPlugin_%_mollie%';
            $test2 = 'kPlugin_' . $kPlugin . '_mollie%';
            $conflicted_arr = Shop::DB()->executeQueryPrepared("SELECT kZahlungsart, cName, cModulId FROM `tzahlungsart` WHERE cModulId LIKE :test1 AND cModulId NOT LIKE :test2", [
                ':test1' => $test1,
                ':test2' => $test2,
            ], 2);
            if ($conflicted_arr && count($conflicted_arr)) {
                foreach ($conflicted_arr as $conflicted) {
                    Shop::DB()->executeQueryPrepared('UPDATE tzahlungsart SET cModulId = :cModulId WHERE kZahlungsart = :kZahlungsart', [
                        ':cModulId' => preg_replace('/^kPlugin_\d+_/', 'kPlugin_' . $kPlugin . '_', $conflicted->cModulId),
                        ':kZahlungsart' => $conflicted->kZahlungsart,
                    ], 3);
                }
            }
        }
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

            Shop::DB()->executeQueryPrepared("INSERT INTO tbestellattribut (kBestellung, cName, cValue) VALUES (:kBestellung, 'mollie_oid', :mollieId1) ON DUPLICATE KEY UPDATE cValue = :mollieId2;", [
                ':kBestellung' => $kBestellung,
                ':mollieId1' => $order->id,
                ':mollieId2' => $order->id,
            ], 3);

            Shop::DB()->executeQueryPrepared("INSERT INTO tbestellattribut (kBestellung, cName, cValue) VALUES (:kBestellung, 'mollie_cBestellNr', :orderId1) ON DUPLICATE KEY UPDATE cValue = :orderId2;", [
                ':kBestellung' => $kBestellung,
                ':orderId1' => $oBestellung->cBestellNr,
                ':orderId2' => $oBestellung->cBestellNr,
            ], 3);

            if (isset($order->metadata->originalOrderNumber)) {
                Shop::DB()->executeQueryPrepared("INSERT INTO tbestellattribut (kBestellung, cName, cValue) VALUES (:kBestellung, 'mollie_cFakeBestellNr', :orderId1) ON DUPLICATE KEY UPDATE cValue = :orderId2;", [
                    ':kBestellung' => $kBestellung,
                    ':orderId1' => $order->metadata->originalOrderNumber,
                    ':orderId2' => $order->metadata->originalOrderNumber,
                ], 3);
            }

            $mPayment = null;
            if ($payments = $order->payments()) {
                /** @var \Mollie\Api\Resources\Payment $payment */
                foreach ($payments as $payment) {
                    if (in_array($payment->status, [PaymentStatus::STATUS_AUTHORIZED, PaymentStatus::STATUS_PAID], true)) {
                        $mPayment = $payment;
                    }
                }
            }
            if ($mPayment) {
                Shop::DB()->executeQueryPrepared("INSERT INTO tbestellattribut (kBestellung, cName, cValue) VALUES (:kBestellung, 'mollie_tid', :mollieId1) ON DUPLICATE KEY UPDATE cValue = :mollieId2;", [
                    ':kBestellung' => $kBestellung,
                    ':mollieId1' => $mPayment->id,
                    ':mollieId2' => $mPayment->id,
                ], 3);
            } else {
                self::JTLMollie()->doLog('Mollie::handleOrder: kein Payment gefunden', $logData);
                return false;
            }

            try {
                // Try to change the orderNumber
                if ($order->orderNumber !== $oBestellung->cBestellNr) {
                    JTLMollie::API()->performHttpCall("PATCH", sprintf('orders/%s', $order->id), json_encode(['orderNumber' => $oBestellung->cBestellNr]));
                }
            } catch (Exception $e) {
                self::JTLMollie()->doLog('Mollie::handleOrder: ' . $e->getMessage(), $logData);
            }

            $_payment = self::getLastPayment($order);

            if ($_payment && $_payment->description !== $oBestellung->cBestellNr) {
                JTLMollie::API()->performHttpCall('PATCH', sprintf('payments/%s', $_payment->id), json_encode(['description' => $oBestellung->cBestellNr]));
            }


            $order->orderNumber = $oBestellung->cBestellNr;
            Payment::updateFromPayment($order, $kBestellung);

            $oIncomingPayment = Shop::DB()->executeQueryPrepared("SELECT * FROM tzahlungseingang WHERE kBestellung = :kBestellung", [':kBestellung' => $oBestellung->kBestellung], 1);
            if (!$oIncomingPayment) {
                $oIncomingPayment = new stdClass();
            }

            // 2. Check PaymentStatus
            switch ($order->status) {
                case OrderStatus::STATUS_PAID:
                case OrderStatus::STATUS_COMPLETED:
                case OrderStatus::STATUS_AUTHORIZED:

                    $cHinweis = $order->id;
                    if ($mPayment) {
                        $cHinweis .= ' / ' . $mPayment->id;
                    }
                    if (Helper::getSetting('wawiPaymentID') === 'ord') {
                        $cHinweis = $order->id;
                    } elseif ($mPayment && Helper::getSetting('wawiPaymentID') === 'tr') {
                        $cHinweis = $mPayment->id;
                    }

                    if ($mPayment->method === PaymentMethod::PAYPAL && isset($mPayment->details, $mPayment->details->paypalReference)) {
                        $cHinweis = $mPayment->details->paypalReference;
                        $oIncomingPayment->cZahler = isset($payment->details->paypalPayerId) ? $payment->details->paypalPayerId : '';
                    }

                    $oIncomingPayment->fBetrag = $order->amount->value;
                    $oIncomingPayment->cISO = $order->amount->currency;
                    $oIncomingPayment->cHinweis = $cHinweis;
                    Mollie::JTLMollie()->addIncomingPayment($oBestellung, $oIncomingPayment);
                    Mollie::JTLMollie()->setOrderStatusToPaid($oBestellung);
                    Mollie::JTLMollie()->doLog('Mollie::handleOrder/PaymentStatus: ' . $order->status . ' => Zahlungseingang (' . $order->amount->value . ')', $logData, LOGLEVEL_DEBUG);
                    break;
                case OrderStatus::STATUS_SHIPPING:
                case OrderStatus::STATUS_PENDING:
                    Mollie::JTLMollie()->setOrderStatusToPaid($oBestellung);
                    Mollie::JTLMollie()->doLog('Mollie::handleOrder/PaymentStatus: ' . $order->status . ' => Bestellung bezahlt, KEIN Zahlungseingang', $logData, LOGLEVEL_NOTICE);
                    break;
                case OrderStatus::STATUS_CANCELED:
                case OrderStatus::STATUS_EXPIRED:
                    Mollie::JTLMollie()->doLog('Mollie::handleOrder/PaymentStatus: ' . $order->status, $logData, LOGLEVEL_ERROR);
                    break;
            }
            return true;
        }
        return false;
    }

    /**
     * @param Order $order
     * @return \Mollie\Api\Resources\Payment|null
     */
    public static function getLastPayment(Order $order)
    {
        $payment = null;
        if ($order->payments()) {
            /** @var \Mollie\Api\Resources\Payment $p */
            foreach ($order->payments() as $p) {
                if (!$payment) {
                    $payment = $p;
                    continue;
                }
                if (strtotime($p->createdAt) > strtotime($payment->createdAt)) {
                    $payment = $p;
                }
            }
        }
        return $payment;
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
                $result[] = Locale::getLocale($sS->cISO, $land);
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
