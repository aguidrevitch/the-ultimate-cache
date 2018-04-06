<?php

class the_ultimate_cache_backend_base
{
    const PREFIX  = 'DRWN';
    const ERROR   = 'error';
    const WARNING = 'warning';
    const DEBUG   = 'debug';

    private $_events = array();

    /**
     * @param string $event
     * @return void
     */
    public function emit($event)
    {
        $args = func_get_args();
        if (isset($this->_events[$event])) {
            foreach ($this->_events[$event] as $callback) {
                call_user_func_array($callback, $args);
            }
        }
        if (isset($this->_events['*'])) {
            foreach ($this->_events['*'] as $callback) {
                call_user_func_array($callback, $args);
            }
        }
    }

    /**
     * @param string $event event name
     * @param callable $callback function to call
     */
    public function on($event = null, $callback)
    {
        if (!is_callable($callback))
            throw new \Exception("Callback $callback is not callable");

        if (!$event)
            $event = '*';

        if (!isset($this->_events[$event]))
            $this->_events[$event] = array();

        $this->_events[$event][] = $callback;
    }

    public function off($event = null, $callback = null)
    {
        $callback_name = null;
        if ($callback && !is_callable($callback)) {
            throw new \Exception("Callback $callback is not callable");
        } elseif ($callback) {
            is_callable($callback, true, $callback_name);
        }

        $events = $event
            ? array($event)
            : array_keys($this->_events);

        foreach ($events as $event) {
            if (!empty($this->_events[$event])) {
                if ($callback) {
                    foreach ($this->_events[$event] as $i => $handler) {
                        if ($handler === $callback) {
                            unset($this->_events[$event][$i]);
                        }
                    }
                } else {
                    unset($this->_events[$event]);
                }
            }
        }
    }

}

class the_ultimate_cache_backend extends the_ultimate_cache_backend_base
{

    protected $dir;

    public function __construct($config)
    {
        if (!isset($config['dir']) || !$config['dir'])
            throw new Exception("Cache dir not set");

        if (!is_dir($config['dir']) || !is_writeable($config['dir']))
            throw new Exception(sprintf("Cache dir %s does not exists or is not writeable", $config['dir']));

        $this->dir = $config['dir'];
    }

    protected function cache_filename($key)
    {
        $dir = rtrim($this->dir, DIRECTORY_SEPARATOR);
        $filename = md5($key);
        return $dir . DIRECTORY_SEPARATOR . substr($filename, 0, 2) . DIRECTORY_SEPARATOR . substr($filename, 2, 2) . DIRECTORY_SEPARATOR . $filename;
    }

    protected function index_filename()
    {
        return rtrim($this->dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . "__index";
    }

    public function verify_position_in_index($position)
    {
        if (false !== ($handle = fopen($this->index_filename(), "rb"))) {
            $istat = fstat($handle);
            if ($position < $istat['size'] && false !== fseek($handle, $position)) {
                if (false !== ($data = fread($handle, strlen(self::PREFIX) + 8 + 4))) {
                    $header = unpack("A" . strlen(self::PREFIX) . "prefix/Qexpires/Lcrc", $data);
                    if ($header['prefix'] === self::PREFIX && $header['crc'] == crc32(substr($data, 0, strlen(self::PREFIX) + 8))) {
                        return $position;
                    }
                }
            }
        }
        return false;
    }

    public function store($key, $data, $ttl = null)
    {
        $filename = $this->cache_filename($key);

        $expires = isset($ttl)
            ? time() + (int)$ttl
            : PHP_INT_MAX;

        $position = false;

        if (file_exists($filename)) {
            if (false !== ($handle = fopen($filename, "rb"))) {
                $meta = unpack("Lposition", fread($handle, 8));
                $position = $this->verify_position_in_index($meta['position']);
            }
        } elseif (is_dir(dirname($filename)) || (false !== @mkdir(dirname($filename), 0755, true))) {
            $position = $this->index_add_expires($key, $expires);
        }

        if (false !== $position) {
            // hope it will still be around after 2038, so storing time as 64bit
            $meta = pack("L", $position);
            // file_put_contents might fail so the
            // index does not reflect the real state of the filesystem
            return file_put_contents($filename, $meta . $data, LOCK_EX);
        }

        return false;
    }

    public function retrieve($key, $now = null)
    {
        $filename = $this->cache_filename($key);
        if (file_exists($filename)) {
            if (false !== ($handle = fopen($filename, "rb"))) {
                if (false !== ($data = fread($handle, 4))) {
                    $meta = unpack("Lposition", $data);
                    if (false !== ($expires = $this->index_read_expires($meta['position']))) {
                        if (is_null($now))
                            $now = time();
                        if ($expires > $now) {
                            $data = '';
                            while (!feof($handle)) {
                                $data .= fread($handle, 65536);
                            }
                            fclose($handle);
                            return $data;
                        }
                    }
                }
                fclose($handle);
            }
        }
        return false;
    }

    protected function index_make_header($expires)
    {
        $header = pack("A" . strlen(self::PREFIX) . "Q", self::PREFIX, $expires);
        return $header . pack("L", crc32($header));
    }

    public function index_read_expires($position)
    {
        if (file_exists($this->index_filename())) {
            if (false !== ($handle = fopen($this->index_filename(), "rb"))) {
                if (false !== flock($handle, LOCK_SH | LOCK_NB)) {
                    if (false !== fseek($handle, $position, SEEK_SET)) {
                        if (false !== ($data = fread($handle, strlen(self::PREFIX) + 8 + 4))) {
                            $header = unpack("A" . strlen(self::PREFIX) . "prefix/Qexpires/Lcrc", $data);
                            if ($header['prefix'] === self::PREFIX && $header['crc'] == crc32(substr($data, 0, strlen(self::PREFIX) + 8))) {
                                flock($handle, LOCK_UN);
                                fclose($handle);
                                return $header['expires'];
                            }
                        }
                    }
                    flock($handle, LOCK_UN);
                } else {
                    $this->emit(self::WARNING, "Unable to lock index file for read");
                }
                fclose($handle);
            }
        }
        return false;
    }

    public function index_update_expires($position, $expires)
    {
        if (false !== ($handle = fopen($this->index_filename(), "cb"))) {
            // TODO: test lock index while updating existing key
            if (false !== flock($handle, LOCK_EX | LOCK_NB)) {
                if (false !== fseek($handle, $position, SEEK_SET)) {
                    $header = $this->index_make_header($expires);
                    $result = fwrite($handle, $header);
                    if (false !== $result) {
                        flock($handle, LOCK_UN);
                        fclose($handle);
                        return $position;
                    }
                }
                flock($handle, LOCK_UN);
            }
            fclose($handle);
        }
        return false;
    }

    public function index_add_expires($key, $expires)
    {
        if (false !== ($handle = fopen($this->index_filename(), "cb"))) {
            // TODO: test lock index while storing new key
            if (false !== flock($handle, LOCK_EX | LOCK_NB)) {
                if (false !== fseek($handle, 0, SEEK_END)) {
                    $position = ftell($handle);
                    $header = $this->index_make_header($expires);
                    $result = fwrite($handle, $header . pack("S", strlen($key)) . substr($key, 0, 65535)); // 8192 in apache in fact);
                    if (false !== $result) {
                        flock($handle, LOCK_UN);
                        fclose($handle);
                        return $position;
                    }
                }
                flock($handle, LOCK_UN);
            }
            fclose($handle);
        }
        return false;
    }

    public function invalidate($keys = array(), $return_urls = false)
    {
        if (!file_exists($this->index_filename()))
            return true;

        if (false !== ($handle = fopen($this->index_filename(), "r+b"))) {
            // TODO: test lock index while storing new key
            if (false !== flock($handle, LOCK_EX | LOCK_NB)) {
                $rekeys = array();
                foreach ($keys as $key) {
                    if (substr($key, -1) == '*') {
                        $rekeys[] = '/^' . preg_replace('/\\\\\*/', '.*?', preg_quote($key, '/')) . '/';
                    } else {
                        $rekeys[] = '/^' . preg_replace('/\\\\\*/', '.*?', preg_quote($key, '/')) . '$/';
                    }
                }

                $plen = strlen(self::PREFIX);
                $data = '';
                $pos = 0;
                $matches = array();
                $urls = array();
                while (!feof($handle)) {
                    $data .= fread($handle, 65536);
                    $valid = $data;
                    while ($valid && strlen($data) >= $plen + 8 + 4 + 2) {
                        $header = unpack("A" . $plen . "prefix/Qexpires/Lcrc/Slen", substr($data, 0, $plen + 8 + 4 + 2));
                        if ($header['prefix'] === self::PREFIX && $header['crc'] == crc32(substr($data, 0, $plen + 8))) {
                            if (strlen($data) >= $plen + 8 + 4 + 2 + $header['len']) {
                                if ($header['expires'] > time()) {
                                    $key = substr($data, $plen + 8 + 4 + 2, $header['len']);
                                    foreach ($rekeys as $re) {
                                        if (preg_match($re, $key)) {
                                            $matches[] = $pos;
                                            if ($return_urls)
                                                $urls[] = $key;
                                            break;
                                        }
                                    }
                                }
                                $data = substr($data, $plen + 8 + 4 + 2 + $header['len']);
                                $pos += $plen + 8 + 4 + 2 + $header['len'];
                            } else {
                                $valid = false;
                            }
                        } else {
                            $valid = false;
                        }
                    }
                }

                if ($data) {
                    flock($handle, LOCK_UN);
                    fclose($handle);
                    $this->emit(self::WARNING, "Cache index invalid, dropping");
                    @unlink($this->index_filename());
                    return false;
                }

                $header = $this->index_make_header(time() - 1);
                foreach ($matches as $i => $pos) {
                    if (false !== fseek($handle, $pos, SEEK_SET)) {
                        if (false === fwrite($handle, $header)) {
                            if ($return_urls)
                                $this->emit(self::WARNING, "Unable to invalidate url " . $urls[$i]);
                            else
                                $this->emit(self::WARNING, "Unable to invalidate record at position " . $pos);
                        }
                    }
                }
                flock($handle, LOCK_UN);
                fclose($handle);

                return $return_urls
                    ? $urls
                    : count($matches);
            }
            fclose($handle);
        }
        return false;
    }
}