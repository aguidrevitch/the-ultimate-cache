<?php

class the_ultimate_cache_backend_base {

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

class the_ultimate_cache_backend extends the_ultimate_cache_backend_base {

    protected $dir;

    public function __construct($config) {
        if (!isset($config['dir']) || !$config['dir'])
            throw new Exception("Cache dir not set");

        if (!file_exists($config['dir']))
            throw new Exception("Cache dir does not exists");

        $this->dir = $config['dir'];
    }

   protected function cache_filename($key) {
        return rtrim($this->dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . md5($key);
    }


    public function store($key, $data) {
        return file_put_contents($this->cache_filename($key), $data, LOCK_EX);
    }

    public function read($key) {
        return file_get_contents($this->cache_filename($key));
    }

    public function invalidate($keys = array()) {
        // to be implemented
        return false;
    }
}