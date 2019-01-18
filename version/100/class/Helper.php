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
         * @var \Plugin
         */
        private static $oPlugin;

        /**
         * Load Vendor Autoloader
         * @return bool
         */
        public static function autoload()
        {
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
            return self::autoload();
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
