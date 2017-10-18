<?php

class the_ultimate_cache_cache {

    protected $collected = '';

    protected $backend;
    protected $server;

    public function __construct($backend, $server) {
        $this->backend     = $backend;
        $this->server      = $server;
    }

    protected function get_key() {
        // TODO: apply rules (strip QS if needed)
        // TODO: apply rules (cache with QS but ignore QS param order)
        return @$this->server['REQUEST_METHOD'] . "|" . @$this->server['REQUEST_URI'];
    }

    protected function request_can_be_cached() {
        if (preg_match('/wordpress_logged_in_/', @$this->server['HTTP_COOKIE'])) {
            return false;
        }
        // TODO: apply rules
        // TODO: allow to cache POST
        return preg_match('/^(GET|HEAD)$/i', @$this->server['REQUEST_METHOD']);
    }

    protected function response_can_be_cached() {
        // make configurable
        return http_response_code() >= 200 && http_response_code() < 300;
    }

    public function handler() {
        if (!$this->request_can_be_cached())
            return false;

        if (false !== ($serialized = $this->backend->read($this->get_key()))) {
            if ($cached = @unserialize($serialized)) {
                http_response_code($cached['code']);
                foreach ($cached['headers'] as $header) {
                    header($header);
                };
                header();
                echo $cached['body'];
                exit;
            }
            $this->backend->delete($filename);
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
            $this->backend->store($this->get_key(), serialize($cached));
        }
    }

    public function invalidate($urls) {
        return true;
    }
}
