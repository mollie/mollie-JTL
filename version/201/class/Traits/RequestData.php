<?php
/**
 * @copyright 2022 WebStollen GmbH
 * @link https://www.webstollen.de
 */

namespace ws_mollie\Traits;

trait RequestData
{
    /**
     * @var null|array
     */
    protected $requestData;

    public function jsonSerialize()
    {
        return $this->requestData;
    }

    public function __get($name)
    {
        if (!$this->requestData) {
            $this->loadRequest();
        }

        return is_string($this->requestData[$name]) ? utf8_decode($this->requestData[$name]) : $this->requestData[$name];
    }

    public function __set($name, $value)
    {
        if (!$this->requestData) {
            $this->requestData = [];
        }

        $this->requestData[$name] = is_string($value) ? utf8_encode($value) : $value;

        return $this;
    }

    /**
     * @param array $options
     * @return $this
     */
    public function loadRequest($options = [])
    {
        return $this;
    }


    public function __serialize()
    {
        return $this->requestData ?: [];
    }

    public function __isset($name)
    {
        return $this->requestData[$name] !== null;
    }
}
