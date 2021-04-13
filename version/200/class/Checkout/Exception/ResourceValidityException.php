<?php


use Mollie\Api\Resources\BaseResource;

class ResourceValidityException extends RuntimeException
{

    const ERROR_REQUIRED = 'required';

    /**
     * @param $error
     * @param array $fields
     * @param null|BaseResource $resource
     * @return ResourceValidityException
     */
    public static function trigger($error, $fields = [], $resource = null)
    {
        return new self(sprintf("Resource invalid, the field(s) [%s] are: %s\n%s", implode(', ', $fields), $error, $resource ? print_r($resource, 1) : ''));
    }
}