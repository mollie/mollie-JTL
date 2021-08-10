<?php
/**
 * @copyright 2021 WebStollen GmbH
 * @link https://www.webstollen.de
 */

namespace ws_mollie;

use Mollie\Api\Exceptions\ApiException;
use Mollie\Api\Exceptions\IncompatiblePlatform;
use Mollie\Api\MollieApiClient;
use Shop;
use ws_mollie\Traits\Plugin;

class API
{
    use Plugin;

    /**
     * @var MollieApiClient
     */
    protected $client;

    /**
     * @var bool
     */
    protected $test;

    /**
     * API constructor.
     * @param bool $test
     */
    public function __construct($test = null)
    {
        $this->test = $test === null ? self::getMode() : $test;
    }

    /**
     * @return bool
     */
    public static function getMode()
    {
        require_once PFAD_ROOT . PFAD_ADMIN . PFAD_INCLUDES . 'benutzerverwaltung_inc.php';

        return self::Plugin()->oPluginEinstellungAssoc_arr['testAsAdmin'] === 'Y' && Shop::isAdmin() && self::Plugin()->oPluginEinstellungAssoc_arr['test_api_key'];
    }

    /**
     * @throws ApiException
     * @throws IncompatiblePlatform
     * @return MollieApiClient
     */
    public function Client()
    {
        if (!$this->client) {
            $this->client = new MollieApiClient(/*new Client([
                RequestOptions::VERIFY => CaBundle::getBundledCaBundlePath(),
                RequestOptions::TIMEOUT => 60
            ])*/);
            $this->client->setApiKey($this->isTest() ? self::Plugin()->oPluginEinstellungAssoc_arr['test_api_key'] : self::Plugin()->oPluginEinstellungAssoc_arr['api_key'])
                ->addVersionString('JTL-Shop/' . JTL_VERSION . JTL_MINOR_VERSION)
                ->addVersionString('ws_mollie/' . self::Plugin()->nVersion);
        }

        return $this->client;
    }

    /**
     * @return bool
     */
    public function isTest()
    {
        return $this->test;
    }
}
