<?php


namespace ws_mollie\Traits;


trait RequestData
{

    /**
     * @var array|null
     */
    protected $requestData;

    /**
     * @param $key string
     * @return mixed|null
     */
    public function RequestData($key)
    {
        if (!$this->getRequestData()) {
            $this->loadRequest();
        }
        return $this->requestData[$key] ?: null;
    }

    /**
     * @return array|null
     */
    public function getRequestData()
    {
        return $this->requestData;
    }

    /**
     * @param $key
     * @param $value
     * @return $this
     */
    public function setRequestData($key, $value)
    {
        if (!$this->requestData) {
            $this->requestData = [];
        }
        $this->requestData[$key] = $value;
        return $this;
    }

    /**
     * @param array $options
     * @return $this
     */
    public function loadRequest($options = []){
        return $this;
    }

    public function jsonSerialize()
    {
        return $this->requestData;
    }

}