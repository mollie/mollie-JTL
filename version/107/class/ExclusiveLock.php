<?php
/**
 * @copyright 2022 WebStollen GmbH
 * @link https://www.webstollen.de
 */

namespace ws_mollie;

use Noodlehaus\Exception;

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
            throw new Exception("Lock Path '{$path}' doesn't exist, or is not writable!");
        }
        //create a new resource or get exisitng with same key
        $this->file = fopen($this->path . "$key.lockfile", 'w+');
    }


    public function __destruct()
    {
        if ($this->own === true) {
            $this->unlock();
        }
    }


    public function lock()
    {
        if (!flock($this->file, LOCK_EX | LOCK_NB)) { //failed
            $key = $this->key;
            error_log("ExclusiveLock::acquire_lock FAILED to acquire lock [$key]");

            return false;
        }
        //ftruncate($this->file, 0); // truncate file
        //write something to just help debugging
        //fwrite( $this->file, "Locked\n");
        fwrite($this->file, 'Locked - ' . microtime(true) . "\n");
        fflush($this->file);

        $this->own = true;

        return true; // success
    }


    public function unlock()
    {
        $key = $this->key;
        if ($this->own === true) {
            if (!flock($this->file, LOCK_UN)) { //failed
                error_log("ExclusiveLock::lock FAILED to release lock [$key]");

                return false;
            }
            //ftruncate($this->file, 0); // truncate file
            //write something to just help debugging
            fwrite($this->file, 'Unlocked - ' . microtime(true) . "\n");
            fflush($this->file);
            $this->own = false;
        } else {
            error_log("ExclusiveLock::unlock called on [$key] but its not acquired by caller");
        }

        return true; // success
    }
}
