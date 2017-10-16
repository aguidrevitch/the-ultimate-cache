<?php
/*
Plugin Name: The Ultimate Cache
Plugin URI: http://example.com
Description: Ultimate Cache
Author: Aleksandr Guidrevitch
Version: 0.0.1
*/


class the_ultimate_cache {

    var $collected = '';

    public function ultimate_cache() {
        return $this->__construct();
    }

    public function __construct() {
        if (is_admin()) {
            return;
        }
        $config = array(
            'dir' => WP_CONTENT_DIR
        );

        require(__DIR__ . '/cache.php');
        $cache = new the_ultimate_cache_cache($config, $_SERVER);
        add_action('plugins_loaded', array($cache, 'handler'), -1);
    }

}

$ultimate_cache = new the_ultimate_cache();
