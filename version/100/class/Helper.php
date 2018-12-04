<?php


namespace ws_mollie {

    /**
     * Class Helper
     * @package ws_mollie
     */
    final class Helper
    {


        /**
         * Is ::autoload() already called?
         *
         * @var bool|null
         */
        private static $_autoload;

        /**
         * Is Licence valid?
         *
         * @var bool|null
         */
        private static $_licence;

        /**
         * @var string
         */
        private static $_tmpLicence;

        /**
         * @var \Plugin
         */
        private static $oPlugin;

        /**
         * Load Vendor Autoloader
         * @return bool
         */
        public static function autoload(){
            if (null === self::$_autoload) {
                if (file_exists(__DIR__ . '/../../../vendor/autoload.php')) {
                    require_once __DIR__ . '/../../../vendor/autoload.php';
                }

                self::$_autoload = spl_autoload_register(function ($class) {
                    $prefix = 'ws_mollie\\';
                    $baseDir = __DIR__ . DIRECTORY_SEPARATOR;

                    $len = strlen($prefix);
                    if (strncmp($prefix, $class, $len) !== 0) {
                        return;
                    }

                    $relativeClass = substr($class, $len);
                    $file = $baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';
                    if (file_exists($file)) {
                        /** @noinspection PhpIncludeInspection */
                        require_once $file;
                    }
                });
            }
            return self::$_autoload;
        }
        
        /**
         * Register PSR-4 autoloader
         * Licence-Check
         * @return bool
         */
        public static function init()
        {
            self::autoload();
            if (null === self::$_licence) {

                if (array_key_exists('_licActivate', $_REQUEST) && $_REQUEST['_licActivate'] === __NAMESPACE__) {
                    self::_licActivate(self::_licCache());
                }

                self::$_licence = false;
                self::$_tmpLicence = md5(time());
                if ($_SERVER['HTTP_HOST'] === 'localhost') {
                    self::$_licence = true;
                } else {
                    self::$_licence = self::licCheck(md5(self::$_tmpLicence)) === md5(self::$_tmpLicence);
                }
            }

            if (!self::$_licence && self::isAdminBackend()) {
                \Shop::Smarty()->assign('defaultTabbertab', self::getAdminmenu('Info'));
            }


            return self::$_autoload && self::$_licence;
        }

        /**
         * @param $oL
         * @return mixed
         */
        private static function _licActivate($oL)
        {
            $oL->nextCheck = 0;
            $oL->fails = 0;
            $oL->disabled = 0;
            self::_licSave($oL);
            return $oL;
        }

        /**
         * @param $oL
         * @param bool $checksum
         * @return mixed
         */
        private static function _licSave($oL, $checksum = false)
        {
            self::setSetting('lic_validUntil', $oL->validUntil);
            self::setSetting('lic_nextCheck', $oL->nextCheck);
            self::setSetting('lic_test', $oL->test);
            if ($checksum === true) {
                self::setSetting('lic_checkSum', base64_encode(sha1($oL->validUntil . $oL->nextCheck . $oL->test . $oL->disabled . $oL->fails . __NAMESPACE__, true)));
            }
            self::setSetting('lic_disabled', (int)$oL->disabled);
            self::setSetting('lic_fails', $oL->fails);
            return $oL;
        }

        /**
         * Sets a Plugin Setting and saves it to the DB
         *
         * @param $name
         * @param $value
         * @return int
         */
        public static function setSetting($name, $value)
        {
            $setting = new \stdClass;
            $setting->kPlugin = self::oPlugin()->kPlugin;
            $setting->cName = $name;
            $setting->cWert = $value;

            if (array_key_exists($name, self::oPlugin()->oPluginEinstellungAssoc_arr)) {
                $return = \Shop::DB()->updateRow('tplugineinstellungen', ['kPlugin', 'cName'], [$setting->kPlugin, $setting->cName], $setting);
            } else {
                $return = \Shop::DB()->insertRow('tplugineinstellungen', $setting);
            }
            self::oPlugin()->oPluginEinstellungAssoc_arr[$name] = $value;
            self::oPlugin(true); // invalidate cache
            return $return;
        }

        /**
         * Get Plugin Object
         *
         * @param bool $force disable Cache
         * @return \Plugin|null
         */
        public static function oPlugin($force = false)
        {
            if ($force === true) {
                self::$oPlugin = new \Plugin(self::oPlugin(false)->kPlugin, true);
            } else if (null === self::$oPlugin) {
                self::$oPlugin = \Plugin::getPluginById(__NAMESPACE__);
            }
            return self::$oPlugin;
        }

        /**
         * @param $oL
         * @return bool|string
         */
        private static function _licValid($oL)
        {
            if ($nextCheck = strtotime($oL->nextCheck)) {
                if ($nextCheck > time()) {
                    if ($validUntil = strtotime($oL->validUntil)) {
                        if ($validUntil > time() || (int)$oL->test === 0) {
                            return md5(self::$_tmpLicence);
                        }
                    }
                }
            }
            return false;

        }

        /**
         * @return \stdClass
         */
        public static function _licCache()
        {
            $oL = new \stdClass();
            $oL->validUntil = self::getSetting('lic_validUntil');
            $oL->nextCheck = self::getSetting('lic_nextCheck');
            $oL->test = self::getSetting('lic_test');
            $oL->checksum = self::getSetting('lic_checkSum');
            $oL->disabled = self::getSetting('lic_disabled') === null ? 0 : (int)self::getSetting('lic_disabled');
            $oL->fails = self::getSetting('lic_fails') ?: 0;
            return $oL;
        }

        /**
         * get a Plugin setting
         *
         * @param $name
         * @return null|mixed
         */
        public static function getSetting($name)
        {
            if (array_key_exists($name, self::oPlugin()->oPluginEinstellungAssoc_arr)) {
                return self::oPlugin()->oPluginEinstellungAssoc_arr[$name];
            }
            return null;
        }

        /**
         * @param $x
         * @return bool|string
         */
        private static function licCheck($x)
        {
            if ($x !== md5(self::$_tmpLicence)) {
                return false;
            }
            $oL = self::_licCache();
            if (self::_licCRC($oL) === true) {
                // CRC OK?
                if (self::_licValid($oL) === md5(self::$_tmpLicence)) {
                    return md5(self::$_tmpLicence);
                }
            }
            if ($oL->disabled === 0) {
                $oL = self::_licLive($oL);

                if ($oL->disabled === 0 && self::_licValid($oL) === md5(self::$_tmpLicence)) {
                    return md5(self::$_tmpLicence);
                }
            }
            return false;

        }

        /**
         * @param $oL
         * @return bool
         */
        private static function _licCRC($oL)
        {
            $crc_valid = false;
            if ($checksum = base64_encode(sha1($oL->validUntil . $oL->nextCheck . $oL->test . $oL->disabled . $oL->fails . __NAMESPACE__, true))) {
                $crc_valid = $checksum == $oL->checksum;
            }
            return $oL->checksum && $crc_valid;
        }

        /**
         * @param $oL
         * @return mixed
         */
        private static function _licLive($oL)
        {
            try {

                if (!function_exists('curl_init')) {
                    throw new \Exception(__NAMESPACE__ . ': cURL needs to be installed!');
                }

                $urlShop = self::getDomain();

                $params = [
                    'domain' => $urlShop,
                    'plugin_version' => (int)self::oPlugin()->nVersion,
                    'plugin_id' => self::oPlugin()->cPluginID,
                    'email' => self::_masterMail(),
                    'shop_version' => (string)(defined('APPLICATION_VERSION') ? APPLICATION_VERSION : JTL_VERSION),
                    'shop_build' => defined('APPLICATION_BUILD_SHA') ? APPLICATION_BUILD_SHA : '',
                    'php_version' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION,
                    'php_extra_version' => PHP_EXTRA_VERSION,
                    'php_os' => PHP_OS,
                    'server' => array_key_exists('SERVER_SOFTWARE', $_SERVER) ? $_SERVER['SERVER_SOFTWARE'] : '',
                ];
                $curl = curl_init('https://lic.dash.bar');
                @curl_setopt($curl, CURLOPT_POST, 1);
                @curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($params)); // http_build_query($params, '', '&'));
                @curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
                @curl_setopt($curl, CURLOPT_TIMEOUT, 3);
                @curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                @curl_setopt($curl, CURLOPT_HEADER, 0);
                @curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

                $data = curl_exec($curl);
                $statusCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);

                @curl_close($curl);
                if ($statusCode !== 200) {
                    throw new \Exception(__NAMESPACE__ . ': Could not fetch licence info: ' . $statusCode);
                }
                /** @noinspection UnserializeExploitsInspection */
                if ($response = json_decode($data)) {

                    $checksum = md5(base64_encode($response->data->validUntil . $response->data->nextCheck . ($response->data->testLicence ? 'Y' : 'N')));

                    if (!$response || !isset($response->data) || $response->data === false || !isset($response->checksum) || $response->checksum !== $checksum) {
                        throw new \Exception(__NAMESPACE__ . ': Invalid licence info: ' . print_r($response, 1));
                    }
                    $oLL = $response->data;
                    if (self::isPresent($oLL->validUntil)) {
                        $oL->validUntil = date('Y-m-d', $oLL->validUntil);
                    }
                    if (self::isPresent($oLL->nextCheck)) {
                        $oL->nextCheck = date('Y-m-d', $oLL->nextCheck);
                    }
                    if (self::isPresent($oLL->testLicence)) {
                        $oL->test = $oLL->testLicence ? 1 : 0;
                    }
                    $oL->fails = 0;
                    self::_licSave($oL, true);

                } else {
                    throw new \Exception(__NAMESPACE__ . ': Could not decode licence info: ' . print_r($response, 1));
                }

            } catch (\Exception $e) {
                $oL->disabled = (++$oL->fails) >= 3 ? 1 : 0;
                $oL->nextCheck = date("Y-m-d", strtotime("+1 DAY"));
                $oL->validUntil = date("Y-m-d", strtotime("+1 DAY"));;

                self::logExc($e, false);
                self::_licSave($oL, true);
            }
            return $oL;
        }


        /**
         * @param $val
         * @return bool
         * @throws \Exception
         */
        private static function ispresent($val)
        {
            if (isset($val)) {
                return true;
            } else {
                throw new \Exception(__NAMESPACE__ . ': Could ned find value from license server ' . print_r($val, 1));
            }
        }

        /**
         * Get Domain frpm URL_SHOP without www.
         *
         * @param string $url
         * @return string
         */
        public static function getDomain($url = URL_SHOP)
        {
            $matches = array();
            @preg_match("/^((http(s)?):\/\/)?(www\.)?([a-zA-Z0-9-\.]+)(\/.*)?$/i", $url, $matches);
            return strtolower(isset($matches[5]) ? $matches[5] : $url);
        }

        /**
         * @return mixed
         */
        private static function _masterMail()
        {
            $settings = \Shop::getSettings(array(CONF_EMAILS));
            return $settings['emails']['email_master_absender'];
        }

        /**
         * @param \Exception $exc
         * @param bool $trace
         * @return void
         */
        public static function logExc(\Exception $exc, $trace = true)
        {
            \Jtllog::writeLog(__NAMESPACE__ . ': ' . $exc->getMessage() . ($trace ? ' - ' . $exc->getTraceAsString() : ''));
        }

        /**
         * Checks if admin session is loaded
         *
         * @return bool
         */
        public static function isAdminBackend()
        {
            return session_name() === 'eSIdAdm';
        }

        /**
         * Returns kAdminmenu ID for given Title, used for Tabswitching
         *
         * @param $name string CustomLink Title
         * @return int
         */
        public static function getAdminmenu($name)
        {
            $kPluginAdminMenu = 0;
            foreach (self::oPlugin()->oPluginAdminMenu_arr as $adminmenu) {
                if (strtolower($adminmenu->cName) == strtolower($name)) {
                    $kPluginAdminMenu = $adminmenu->kPluginAdminMenu;
                    break;
                }
            }
            return $kPluginAdminMenu;
        }

    }


}
