<?php
/**
 * @copyright 2021 WebStollen GmbH
 * @link https://www.webstollen.de
 */

namespace ws_mollie\Traits;

trait Jsonable
{
    public function toArray()
    {
        return $this->jsonSerialize();
    }

    public function jsonSerialize()
    {
        return array_filter(get_object_vars($this), static function ($value) {
            return !($value === null || (is_string($value) && $value === ''));
        });
    }
}
