<?php

class the_ultimate_cache_backend_base
{

    const ERROR = 'error';
    const DEBUG = 'debug';

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
        $file = md5($key);
        return  $dir . DIRECTORY_SEPARATOR . substr($file, 0, 2) . DIRECTORY_SEPARATOR . substr($file, 2, 2) . DIRECTORY_SEPARATOR . $file;
    }

    public function store($key, $data)
    {
        $filename = $this->cache_filename($key);
        if (false !== @mkdir(dirname($filename), 0755, true)) {
            return file_put_contents($filename, $data, LOCK_EX);
        }
        return false;
    }

    public function retrieve($key)
    {
        $filename = $this->cache_filename($key);
        if (file_exists($filename)) {
            return file_get_contents($this->cache_filename($key));
        }
        return false;
    }

    public function invalidate($keys = array())
    {
        // to be implemented
        return false;
    }
}