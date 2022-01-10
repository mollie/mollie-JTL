<?php
/**
 * @copyright 2022 WebStollen GmbH
 * @link https://www.webstollen.de
 */

namespace ws_mollie\Model;

use JsonSerializable;
use RuntimeException;
use Shop;
use stdClass;

abstract class AbstractModel implements JsonSerializable
{
    const TABLE   = null;
    const PRIMARY = null;


    const NULL = '_DBNULL_';

    protected $new = false;
    /**
     * @var stdClass
     */
    protected $data;

    public function __construct($data = null)
    {
        $this->data = $data;
        if (!$data) {
            $this->new = true;
        }
    }

    public static function fromID($id, $col = 'kID', $failIfNotExists = false)
    {
        if (
            $payment = Shop::DB()->executeQueryPrepared(
                'SELECT * FROM ' . static::TABLE . " WHERE {$col} = :id",
                [':id' => $id],
                1
            )
        ) {
            return new static($payment);
        }
        if ($failIfNotExists) {
            throw new RuntimeException(sprintf('Model %s in %s nicht gefunden!', $id, static::TABLE));
        }

        return new static();
    }

    /**
     * @return null|mixed|stdClass
     */
    public function jsonSerialize()
    {
        return $this->data;
    }

    public function __get($name)
    {
        if (isset($this->data->$name)) {
            return $this->data->$name;
        }

        return null;
    }

    public function __set($name, $value)
    {
        if (!$this->data) {
            $this->data = new stdClass();
        }
        $this->data->$name = $value;
    }

    public function __isset($name)
    {
        return isset($this->data->$name);
    }

    /**
     * @return bool
     */
    public function save()
    {
        if (!$this->data) {
            throw new RuntimeException('No Data to save!');
        }

        if ($this->new) {
            Shop::DB()->insert(static::TABLE, $this->data);
            $this->new = false;

            return true;
        }
        Shop::DB()->update(static::TABLE, static::PRIMARY, $this->data->{static::PRIMARY}, $this->data);

        return true;
    }
}
