<?php


namespace ws_mollie\Checkout;


use JsonSerializable;
use ws_mollie\Traits\Plugin;
use ws_mollie\Traits\RequestData;

abstract class AbstractResource implements JsonSerializable
{
    use Plugin;
    use RequestData;

    public function __get($name)
    {
        return $this->RequestData($name);
    }

    public function __set($name, $value)
    {
        return $this->setRequestData($name, $value);
    }

    public function __serialize()
    {
        return $this->getRequestData() ?: [];
    }

    public function __isset($name)
    {
        return $this->RequestData($name) !== null;
    }
}