<?php


namespace ws_mollie\Traits;


trait Jsonable
{
    public function toArray()
    {
        return $this->jsonSerialize();
    }

    public function jsonSerialize()
    {
        return array_filter(get_object_vars($this), function ($value) {
            return $value === null || (is_string($value) && $value === '') ? false : true;
        });
    }
}