<?php

/**
 * Class the_ultimate_cache_cache
 * @property the_ultimate_cache_backend $backend
 * @property array() $server
 * @property array() $rules
 */
class the_ultimate_cache_cache
{

    protected $collected = '';

    protected $backend;
    protected $server;
    protected $rules;

    protected $expires;

    public function __construct($backend, $server, $rules = array())
    {
        $this->backend = $backend;
        $this->server = $server;
        $this->rules = $rules;
    }

    protected function get_key()
    {
        // TODO: apply rules (strip QS if needed)
        // TODO: apply rules (cache with QS but ignore QS param order)
        return @$this->server['REQUEST_METHOD'] . "|" . @$this->server['REQUEST_URI'];
    }

    protected function request_can_be_cached()
    {
        if (preg_match('/wordpress_logged_in_/', @$this->server['HTTP_COOKIE'])) {
            return false;
        }
        if ($this->expires && $this->expires <= time()) {
            return false;
        }
        return preg_match('/^(GET|HEAD)$/i', $this->server['REQUEST_METHOD']);
    }

    protected function response_can_be_cached()
    {
        // make configurable
        return http_response_code() >= 200 && http_response_code() < 300;
    }

    public function handler()
    {
        if (isset($this->rules['request'])) {
            foreach ($this->rules['request'] as $rule) {
                if (substr($rule['url'], -1) == '*') {
                    $re = '/^' . preg_replace('/\\\\\*/', '.*?', preg_quote($rule['url'], '/')) . '/';
                } else {
                    $re = '/^' . preg_replace('/\\\\\*/', '.*?', preg_quote($rule['url'], '/')) . '$/';
                }
                $uri = preg_replace('/\?.*/', '', $this->server['REQUEST_URI']);
                /**
                 * ttl
                 *      -1 Never
                 *       0+ Seconds
                 *    null Forever
                 */
                if (preg_match($re, $uri) && $rule['ttl']) {
                    $this->expires = time() + $rule['ttl'];
                    break;
                }
            }
        }

        if (!$this->request_can_be_cached())
            return false;

        if (false !== ($serialized = $this->backend->retrieve($this->get_key()))) {
            if ($cached = @unserialize($serialized)) {
                http_response_code($cached['code']);
                foreach ($cached['headers'] as $header) {
                    header($header);
                };
                header('X-Cached-By: https://wordpress.org/plugins/the-ultimate-cache');
                echo $cached['body'];
                exit;
            }
        }
        @ob_start(array($this, 'collect'));
        register_shutdown_function(array($this, 'shutdown'));
    }

    public function collect($buffer)
    {
        $this->collected .= $buffer;
    }

    public function shutdown()
    {
        if ($this->response_can_be_cached()) {
            @header_remove('Set-Cookie');
            @header_remove('Set-Cookie2');
            $cached = array(
                'time' => time(),
                'method' => @$this->server['REQUEST_METHOD'],
                'uri' => $this->get_key(),
                'code' => http_response_code(),
                'headers' => headers_list(),
                'body' => $this->collected,
            );
            $this->backend->store($this->get_key(), serialize($cached), $this->expires);
        }
        echo $this->collected;
    }

    public function invalidate($urls)
    {
        return $this->backend->invalidate($urls);
    }
}
