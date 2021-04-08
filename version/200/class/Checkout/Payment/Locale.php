<?php


namespace ws_mollie\Checkout\Payment;


use ws_mollie\Traits\Plugin;

class Locale
{
    use Plugin;

    protected static $langs = [
        'ger' => ['lang' => 'de', 'country' => ['DE', 'AT', 'CH']],
        'fre' => ['lang' => 'fr', 'country' => ['BE', 'FR']],
        'dut' => ['lang' => 'nl', 'country' => ['BE', 'NL']],
        'spa' => ['lang' => 'es', 'country' => ['ES']],
        'ita' => ['lang' => 'it', 'country' => ['IT']],
        'pol' => ['lang' => 'pl', 'country' => ['PL']],
        'hun' => ['lang' => 'hu', 'country' => ['HU']],
        'por' => ['lang' => 'pt', 'country' => ['PT']],
        'nor' => ['lang' => 'nb', 'country' => ['NO']],
        'swe' => ['lang' => 'sv', 'country' => ['SE']],
        'fin' => ['lang' => 'fi', 'country' => ['FI']],
        'dan' => ['lang' => 'da', 'country' => ['DK']],
        'ice' => ['lang' => 'is', 'country' => ['IS']],
        'eng' => ['lang' => 'en', 'country' => ['GB', 'US']],
    ];

    public static function getLocale($cISOSprache = null, $country = null)
    {
        if($cISOSprache === null){
            $cISOSprache = gibStandardsprache()->cISO;
        }

        if (array_key_exists($cISOSprache, self::$langs)) {
            $locale = self::$langs[$cISOSprache]['lang'];
            if ($country && is_array(self::$langs[$cISOSprache]['country']) && in_array($country, self::$langs[$cISOSprache]['country'], true)) {
                $locale .= '_' . strtoupper($country);
            } else {
                $locale .= '_' . self::$langs[$cISOSprache]['country'][0];
            }
            return $locale;
        }

        return self::Plugin()->oPluginEinstellungAssoc_arr['fallbackLocale'];
    }
}