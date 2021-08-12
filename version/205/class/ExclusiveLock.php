<?php

/**
 * @copyright 2021 WebStollen GmbH
 * @link https://www.webstollen.de
 */

namespace ws_mollie;

use RuntimeException;

class ExclusiveLock
{
    protected $key;           //user given value
    protected $file;          //resource to lock
    protected $own  = false; //have we locked resource
    protected $path = '';

    public function __construct($key, $path = '')
    {
        $this->key  = $key;
        $this->path = rtrim(realpath($path), '/') . '/';
        if (!is_dir($path) || !is_writable($path)) {
            throw new RuntimeException("Lock Path '$path' doesn't exist, or is not writable!");
        }
        //create a new resource or get exisitng with same key
        $this->file = fopen($this->path . "$key.lockfile", 'wb+');
    }


    public function __destruct()
    {
        if ($this->own === true) {
            $this->unlock();
        }
    }

    /** @noinspection ForgottenDebugOutputInspection */
    public function unlock()
    {
        $key = $this->key;
        if ($this->own === true) {
            if (!flock($this->file, LOCK_UN)) { //failed
                error_log("ExclusiveLock::lock FAILED to release lock [$key]");

                return false;
            }
            fwrite($this->file, 'Unlocked - ' . microtime(true) . "\n");
            fflush($this->file);
            $this->own = false;
        } else {
            error_log("ExclusiveLock::unlock called on [$key] but its not acquired by caller");
        }

        return true; // success
    }

    public function lock()
    {
        if (!flock($this->file, LOCK_EX | LOCK_NB)) { //failed
            return false;
        }
        fwrite($this->file, 'Locked - ' . microtime(true) . "\n");
        fflush($this->file);

        $this->own = true;

        return true; // success
    }
}
