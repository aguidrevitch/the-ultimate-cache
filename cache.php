<?php

class the_ultimate_cache_cache {

    var $collected = '';
    var $dir;
    var $server;

    public function ultimate_cache_cache($config, $server) {
        return $this->__construct($config, $server);
    }

    public function __construct($config, $server) {
        if (!isset($config['dir']) || !$config['dir'])
            throw new Exception("Cache dir not set");

        if (!file_exists($config['dir']))
            throw new Exception("Cache dir does not exists");

        $this->dir = $config['dir'];
        $this->server = $server;
    }

    protected function current_uri() {
        // rules apply
        return @$this->server['REQUEST_URI'];
    }

    protected function cache_filename() {
        $filename = @$this->server['REQUEST_METHOD'] . "|" . $this->current_uri();
        return rtrim($this->dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . md5($filename);
    }

    protected function request_can_be_cached() {
        // rules apply
        if (preg_match('/wordpress_logged_in_/', @$this->server['HTTP_COOKIE'])) {
            return false;
        }
        // allow to cache POST
        return preg_match('/^(GET|HEAD)$/i', @$this->server['REQUEST_METHOD']);
    }

    protected function response_can_be_cached() {
        // make configurable
        return http_response_code() >= 200 && http_response_code() < 300;
    }

    public function handler() {
        if (!$this->request_can_be_cached())
            return false;

        $filename = $this->cache_filename();
        if (file_exists($filename)) {
            $serialized = file_get_contents($filename);
            if ($cached = @unserialize($serialized)) {
                http_response_code($cached['code']);
                foreach ($cached['headers'] as $header) {
                    header($header);
                };
                header();
                echo $cached['body'];
                exit;
            }
            @unlink($filename);
        }
        @ob_start(array($this, 'collect'));
        register_shutdown_function(array($this, 'shutdown'));
    }

    public function collect($buffer) {
        $this->collected .= $buffer;
    }

    public function shutdown() {
        if ($this->response_can_be_cached()) {
            @header_remove('Set-Cookie');
            @header_remove('Set-Cookie2');
            $cached = array(
                'time' => time(),
                'method' => @$this->server['REQUEST_METHOD'],
                'uri' => $this->current_uri(),
                'code' => http_response_code(),
                'headers' => headers_list(),
                'body' => $this->collected,
            );
            file_put_contents($this->cache_filename(), serialize($cached), LOCK_EX);
        }
    }
}
