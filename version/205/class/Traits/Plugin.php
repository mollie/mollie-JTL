<?php

/**
 * @copyright 2021 WebStollen GmbH
 * @link https://www.webstollen.de
 */

namespace ws_mollie\Traits;

use RuntimeException;

trait Plugin
{
    /**
     * @var \Plugin
     */
    protected static $oPlugin;

    /**
     * @return \Plugin
     */
    public static function Plugin()
    {
        if (!(self::$oPlugin = \Plugin::getPluginById('ws_mollie'))) {
            throw new RuntimeException('Could not load Plugin!');
        }

        return self::$oPlugin;
    }
}
