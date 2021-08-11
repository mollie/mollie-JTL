<?php
/**
 * @copyright 2021 WebStollen GmbH
 * @link https://www.webstollen.de
 */

namespace ws_mollie {

    use Exception;
    use Jtllog;
    use PclZip;
    use Plugin;
    use Shop;
    use stdClass;

    if (!class_exists('ws_mollie\Helper')) {
        /**
         * Class Helper
         * @package ws_mollie
         */
        final class Helper
        {
            /**
             * Is ::autoload() already called?
             *
             * @var null|bool
             */
            private static $_autoload;

            /**
             * @var Plugin
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
             * @throws Exception
             */
            public static function selfupdate()
            {
                if (function_exists('opcache_reset')) {
                    opcache_reset();
                }

                // 0. GET RELEASE INFO
                $release    = self::getLatestRelease(true);
                $url        = $release->short_url != '' ? $release->short_url : $release->full_url;
                $filename   = basename($release->full_url);
                $tmpDir     = PFAD_ROOT . PFAD_COMPILEDIR;
                $pluginsDir = PFAD_ROOT . PFAD_PLUGIN;

                // 1. PRE-CHECKS
                if (file_exists($pluginsDir . self::oPlugin()->cVerzeichnis . '/.git') && is_dir($pluginsDir . self::oPlugin()->cVerzeichnis . '/.git')) {
                    throw new Exception('Pluginordner enthält ein GIT Repository, kein Update möglich!');
                }

                if (!function_exists('curl_exec')) {
                    throw new Exception('cURL ist nicht verfügbar!!');
                }
                if (!is_writable($tmpDir)) {
                    throw new Exception("Temporäres Verzeichnis_'{$tmpDir}' ist nicht beschreibbar!");
                }
                if (!is_writable($pluginsDir . self::oPlugin()->cVerzeichnis)) {
                    throw new Exception("Plugin Verzeichnis_'" . $pluginsDir . self::oPlugin()->cVerzeichnis . "' ist nicht beschreibbar!");
                }
                if (file_exists($tmpDir . $filename)) {
                    if (!unlink($tmpDir . $filename)) {
                        throw new Exception("Temporäre Datei '" . $tmpDir . $filename . "' konnte nicht gelöscht werden!");
                    }
                }

                // 2. DOWNLOAD
                $fp = fopen($tmpDir . $filename, 'w+');
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_TIMEOUT, 50);
                curl_setopt($ch, CURLOPT_FILE, $fp);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_exec($ch);
                $info = curl_getinfo($ch);
                curl_close($ch);
                fclose($fp);
                if ($info['http_code'] !== 200) {
                    throw new Exception("Unerwarteter Status Code '" . $info['http_code'] . "'!");
                }
                if ($info['download_content_length'] <= 0) {
                    throw new Exception("Unerwartete Downloadgröße '" . $info['download_content_length'] . "'!");
                }

                // 3. UNZIP
                require_once PFAD_ROOT . PFAD_PCLZIP . 'pclzip.lib.php';
                $zip     = new PclZip($tmpDir . $filename);
                $content = $zip->listContent();

                if (!is_array($content) || !isset($content[0]['filename']) || strpos($content[0]['filename'], '.') !== false) {
                    throw new Exception('Das Zip-Archiv ist leider ungültig!');
                }
                $unzipPath = PFAD_ROOT . PFAD_PLUGIN;
                $res       = $zip->extract(PCLZIP_OPT_PATH, $unzipPath, PCLZIP_OPT_REPLACE_NEWER);
                if ($res !== 0) {
                    header('Location: ' . Shop::getURL() . DIRECTORY_SEPARATOR . PFAD_ADMIN . 'pluginverwaltung.php', true);
                } else {
                    throw new Exception('Entpacken fehlgeschlagen: ' . $zip->errorCode());
                }
            }

            /**
             * @param bool $force
             * @throws Exception
             * @return mixed
             */
            public static function getLatestRelease($force = false)
            {
                $lastCheck   = (int)self::getSetting(__NAMESPACE__ . '_upd');
                $lastRelease = file_exists(PFAD_ROOT . PFAD_COMPILEDIR . __NAMESPACE__ . '_upd') ? file_get_contents(PFAD_ROOT . PFAD_COMPILEDIR . __NAMESPACE__ . '_upd') : false;
                if ($force || !$lastCheck || !$lastRelease || ($lastCheck + 12 * 60 * 60) < time()) {
                    $curl = curl_init('https://api.dash.bar/release/' . __NAMESPACE__);
                    @curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 5);
                    @curl_setopt($curl, CURLOPT_TIMEOUT, 5);
                    @curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                    @curl_setopt($curl, CURLOPT_HEADER, 0);
                    @curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
                    @curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

                    $data       = curl_exec($curl);
                    $statusCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
                    @curl_close($curl);
                    if ($statusCode !== 200) {
                        throw new Exception(__NAMESPACE__ . ': Could not fetch release info: ' . $statusCode);
                    }
                    $json = json_decode($data);
                    if (json_last_error() || $json->status != 'ok') {
                        throw new Exception(__NAMESPACE__ . ': Could not decode release info: ' . $data);
                    }
                    self::setSetting(__NAMESPACE__ . '_upd', time());
                    file_put_contents(PFAD_ROOT . PFAD_COMPILEDIR . __NAMESPACE__ . '_upd', json_encode($json->data));

                    return $json->data;
                }

                return json_decode($lastRelease);
            }

            /**
             * Register PSR-4 autoloader
             * Licence-Check
             * @return bool
             */
            public static function init()
            {
                ini_set('xdebug.default_enable', defined('WS_XDEBUG_ENABLED'));

                return self::autoload();
            }

            /**
             * @var stdClass[]
             */
            public static $alerts = [];

            /**
             * Usage:
             *
             * Helper::addAlert('Success Message', 'success', 'namespace');
             *
             * @param $content
             * @param $type
             * @param $namespace
             */
            public static function addAlert($content, $type, $namespace)
            {
                if (!array_key_exists($namespace, self::$alerts)) {
                    self::$alerts[$namespace] = new stdClass();
                }

                self::$alerts[$namespace]->{$type . '_' . microtime(true)} = $content;
            }

            /**
             * Usage in Smarty:
             *
             * {ws_mollie\Helper::showAlerts('namespace')}
             *
             * @param $namespace
             * @throws \SmartyException
             * @return string
             */
            public static function showAlerts($namespace)
            {
                if (array_key_exists($namespace, self::$alerts) && file_exists(self::oPlugin()->cAdminmenuPfad . '../tpl/_alerts.tpl')) {
                    Shop::Smarty()->assign('alerts', self::$alerts[$namespace]);

                    return Shop::Smarty()->fetch(self::oPlugin()->cAdminmenuPfad . '../tpl/_alerts.tpl');
                }

                return '';
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
                $setting          = new stdClass();
                $setting->kPlugin = self::oPlugin()->kPlugin;
                $setting->cName   = $name;
                $setting->cWert   = $value;

                if (array_key_exists($name, self::oPlugin()->oPluginEinstellungAssoc_arr)) {
                    $return = Shop::DB()->updateRow('tplugineinstellungen', ['kPlugin', 'cName'], [$setting->kPlugin, $setting->cName], $setting);
                } else {
                    $return = Shop::DB()->insertRow('tplugineinstellungen', $setting);
                }
                self::oPlugin()->oPluginEinstellungAssoc_arr[$name] = $value;
                self::oPlugin(true); // invalidate cache

                return $return;
            }

            /**
             * Get Plugin Object
             *
             * @param bool $force disable Cache
             * @return null|Plugin
             */
            public static function oPlugin($force = false)
            {
                if ($force === true) {
                    self::$oPlugin = new Plugin(self::oPlugin(false)->kPlugin, true);
                } elseif (null === self::$oPlugin) {
                    self::$oPlugin = Plugin::getPluginById(__NAMESPACE__);
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
                if (array_key_exists($name, self::oPlugin()->oPluginEinstellungAssoc_arr ?: [])) {
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
                $matches = [];
                @preg_match("/^((http(s)?):\/\/)?(www\.)?([a-zA-Z0-9-\.]+)(\/.*)?$/i", $url, $matches);

                return strtolower(isset($matches[5]) ? $matches[5] : $url);
            }

            /**
             * @param bool $e
             * @return mixed
             */
            public static function getMasterMail($e = false)
            {
                $settings = Shop::getSettings([CONF_EMAILS]);
                $mail     = trim($settings['emails']['email_master_absender']);
                if ($e === true && $mail != '') {
                    $mail  = base64_encode($mail);
                    $eMail = '';
                    foreach (str_split($mail, 1) as $c) {
                        $eMail .= chr(ord($c) ^ 0x00100110);
                    }

                    return base64_encode($eMail);
                }

                return $mail;
            }

            /**
             * @param Exception $exc
             * @param bool      $trace
             * @return void
             */
            public static function logExc(Exception $exc, $trace = true)
            {
                Jtllog::writeLog(__NAMESPACE__ . ': ' . $exc->getMessage() . ($trace ? ' - ' . $exc->getTraceAsString() : ''));
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

}
