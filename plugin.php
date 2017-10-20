<?php

class the_ultimate_cache_plugin {

    protected $failed;
    protected $api;

    public function the_ultimate_cache() {
        return $this->__construct();
    }

    public function __construct() {
        if (is_admin()) {
            // add admin bar button 'Clear Cache'
            add_action('admin_bar_menu', [$this, 'admin_bar_item'], 100);

            add_action('network_admin_menu', [$this, 'menu_item']);
            add_action('admin_menu', [$this, 'menu_item']); // fires first, before admin_init
            // add_action('admin_init', [$this, 'setup_settings']);

            add_action('admin_notices', [$this, 'cleared_cache_notice']);

            // ajax action to clear cache
            add_action('wp_ajax_ultimatecache_clear_cache_full', [$this, 'action_clear_cache_full']);
            add_action('wp_ajax_ultimatecache_clear_cache_current', [$this, 'action_clear_cache_current']);

            // clear cache on theme switch
            add_action('switch_theme', [$this, 'purge_everything']);

            // clear cache on theme customize
            add_action('customize_save_after', [$this, 'purge_everything']);
            add_action('ultimatecache_post_related_links', [$this, 'get_post_related_links'], 10, 2);

            add_action('transition_post_status', [$this, 'on_post_status_change'], 10, 3);
            add_action('save_post', array($this, 'save_post'));
            return;

        } else {
            // not admin area
            $config = array(
                'dir' => WP_CONTENT_DIR . '/ultimate-cache'
            );

            require(__DIR__ . '/backend.php');
            require(__DIR__ . '/cache.php');
            $backend = new the_ultimate_cache_backend($config);
            $cache = new the_ultimate_cache_cache($backend, $_SERVER);
            add_action('plugins_loaded', array($cache, 'handler'), -1);
        }
    }

    public function on_backend_event($event, $message)
    {
        if ($event == the_ultimate_cache_backend::ERROR)
            $this->failed = true;

        $message = sprintf("[%s] [%s]\n%s\n", date('Y-m-d H:i:sO'), strtoupper($event), $message);

        if (WP_DEBUG)
            error_log($message, 3, WP_CONTENT_DIR . '/ultimatecache.log');
    }

    protected function read_settings() {
    }

    public function get_backend()
    {
        if ($this->backend)
            return $this->backend;

        require_once(__DIR__ . '/backend.php');
        $settings = array(
            'dir' => '/tmp'
        );

        $this->backend = new the_ultimate_cache_backend($settings);
        $this->backend->on('*', [$this, 'on_backend_event']);
        return $this->backend;
    }

    public function purge_everything()
    {
        $urls = array(trailingslashit(get_site_url()) . '*');
        $urls = apply_filters('ultimatecache_urls', $urls);

        $this->failed = !$this->get_backend()->invalidate($urls);
        return !$this->failed;
    }

    public function on_post_status_change($new_status, $old_status, $post)
    {
        if (!apply_filters('ultimatecache_invalidate_allowed', 1)) {
            return null;
        }

        if (is_a($post, 'WP_Post') == false) {
            return null;
        }

        if (get_permalink($post->ID) != true) {
            return null;
        }

        if (is_int(wp_is_post_autosave($post->ID)) || is_int(wp_is_post_revision($post->ID))) {
            return null;
        }

        if (($old_status == 'publish' && $new_status != 'publish') || $new_status == 'publish') {
            $urls = apply_filters('ultimatecache_post_related_links', [], $post->ID);
            $urls = apply_filters('ultimatecache_urls', $urls);

            $this->failed = !$this->getApi()->invalidate($urls);
            return !$this->failed;
        }
    }

    public function get_post_related_links($listofurls, $postId)
    {
        $post_type = get_post_type($postId);

        //Purge taxonomies terms URLs
        $post_type_taxonomies = get_object_taxonomies($post_type);

        foreach ($post_type_taxonomies as $taxonomy) {
            $terms = get_the_terms($postId, $taxonomy);

            if (empty($terms) || is_wp_error($terms)) {
                continue;
            }

            foreach ($terms as $term) {
                $term_link = get_term_link($term);
                if (!is_wp_error($term_link)) {
                    array_push($listofurls, $term_link);
                    array_push($listofurls, trailingslashit($term_link) . 'page/*');
                }
            }
        }

        // Author URL
        array_push(
            $listofurls,
            get_author_posts_url(get_post_field('post_author', $postId)),
            trailingslashit(get_author_posts_url(get_post_field('post_author', $postId))) . 'page/*',
            get_author_feed_link(get_post_field('post_author', $postId))
        );

        // Archives and their feeds
        if (get_post_type_archive_link($post_type) == true) {
            array_push(
                $listofurls,
                get_post_type_archive_link($post_type),
                get_post_type_archive_feed_link($post_type)
            );
        }

        // Post URL
        array_push($listofurls, get_permalink($postId));

        // Also clean URL for trashed post.
        if (get_post_status($postId) == 'trash') {
            $trashpost = get_permalink($postId);
            $trashpost = str_replace('__trashed', '', $trashpost);
            array_push($listofurls, $trashpost, trailingslashit($trashpost) . 'feed/');
        }

        // Feeds
        array_push(
            $listofurls,
            get_bloginfo_rss('rdf_url'),
            get_bloginfo_rss('rss_url'),
            get_bloginfo_rss('rss2_url'),
            get_bloginfo_rss('atom_url'),
            get_bloginfo_rss('comments_rss2_url'),
            get_post_comments_feed_link($postId)
        );

        // Home Page and (if used) posts page
        $pageLink = get_permalink(get_option('page_for_posts'));
        if (is_string($pageLink) && !empty($pageLink) && get_option('show_on_front') == 'page') {
            array_push($listofurls, $pageLink);
        }

        return $listofurls;
    }

    public function admin_bar_item($wp_admin_bar)
    {
        if (!current_user_can('publish_posts')) {
            return;
        }

        $wp_admin_bar->add_node([
            'id' => 'ultimatecache',
            'title' => 'Clear Ultimate Cache',
            'href' => wp_nonce_url(admin_url('admin-ajax.php?action=ultimatecache_clear_cache_full&source=adminbar'), 'ultimatecache_clear_cache_full', 'ultimatecache_nonce'),
            'meta' => ['title' => 'Reset Cache'],
            'parent' => 'top-secondary'
        ]);
    }

    public function menu_item()
    {
        add_menu_page(
            'Ultimate Cache',
            'Ultimate Cache',
            'editor',
            'ultimatecache-cache',
            null,
            'data:image/svg+xml;base64,PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0iVVRGLTgiIHN0YW5kYWxvbmU9Im5vIj8+PHN2ZyB3aWR0aD0iNDBweCIgaGVpZ2h0PSI0MHB4IiB2aWV3Qm94PSIwIDAgNDAgNDAiIHZlcnNpb249IjEuMSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIiB4bWxuczp4bGluaz0iaHR0cDovL3d3dy53My5vcmcvMTk5OS94bGluayI+ICAgICAgICA8dGl0bGU+QXJ0Ym9hcmQ8L3RpdGxlPiAgICA8ZGVzYz5DcmVhdGVkIHdpdGggU2tldGNoLjwvZGVzYz4gICAgPGRlZnM+PC9kZWZzPiAgICA8ZyBpZD0iUGFnZS0xIiBzdHJva2U9Im5vbmUiIHN0cm9rZS13aWR0aD0iMSIgZmlsbD0ibm9uZSIgZmlsbC1ydWxlPSJldmVub2RkIj4gICAgICAgIDxnIGlkPSJBcnRib2FyZCIgZmlsbD0iI0ZDMjM3MyI+ICAgICAgICAgICAgPGcgaWQ9ImRhcndpbmFwcHNfMV8iIHRyYW5zZm9ybT0idHJhbnNsYXRlKDAuMDAwMDAwLCAxMy4wMDAwMDApIj4gICAgICAgICAgICAgICAgPHBhdGggZD0iTTMzLjY1NjY4NDksMC4wNDUzOTEzMDQzIEwzMy42NTY2ODQ5LDE0LjgyMTUyMTcgTDM5LjUyNDkzMTEsMTQuODIxNTIxNyBMMzkuNTI0OTMxMSwwLjA0NTM5MTMwNDMgTDMzLjY1NjY4NDksMC4wNDUzOTEzMDQzIEwzMy42NTY2ODQ5LDAuMDQ1MzkxMzA0MyBMMzMuNjU2Njg0OSwwLjA0NTM5MTMwNDMgTDMzLjY1NjY4NDksMC4wNDUzOTEzMDQzIEwzMy42NTY2ODQ5LDAuMDQ1MzkxMzA0MyBaIE0xNy4xMDI1NTM1LDEwLjE5MDM0NzggTDIxLjAzNTI2NzUsMTQuNDM4MjE3NCBMMzIuNTEzNjAzNCw0LjYwMDkxMzA0IEwyOC41ODA4ODk1LDAuMzUzMDQzNDc4IEwxNy4xMDI1NTM1LDEwLjE5MDM0NzggTDE3LjEwMjU1MzUsMTAuMTkwMzQ3OCBMMTcuMTAyNTUzNSwxMC4xOTAzNDc4IEwxNy4xMDI1NTM1LDEwLjE5MDM0NzggTDE3LjEwMjU1MzUsMTAuMTkwMzQ3OCBaIE0wLjAyNDQwMjg2MjUsOC44NzUyNjA4NyBMMi4zODM3NzQzNiwxNC4wOTAyMTc0IEwxNi40ODA5MjI3LDguMTg4MDg2OTYgTDE0LjEyMTU1MTIsMi45NzMxMzA0MyBMMC4wMjQ0MDI4NjI1LDguODc1MjYwODcgTDAuMDI0NDAyODYyNSw4Ljg3NTI2MDg3IEwwLjAyNDQwMjg2MjUsOC44NzUyNjA4NyBMMC4wMjQ0MDI4NjI1LDguODc1MjYwODcgTDAuMDI0NDAyODYyNSw4Ljg3NTI2MDg3IFoiIGlkPSJTaGFwZSI+PC9wYXRoPiAgICAgICAgICAgIDwvZz4gICAgICAgIDwvZz4gICAgPC9nPjwvc3ZnPg=='
        );
        add_submenu_page(
            'ultimatecache-cache',
            'Clear Cache',
            'Clear Cache',
            'manage_options',
            'ultimatecache-clear-cache',
            [$this, 'page_clear_cache']
        );
        add_submenu_page(
            'ultimatecache-cache',
            'Page Rules',
            'Page Rules',
            'manage_options',
            'ultimatecache-page-rules',
            [$this, 'page_page_rules']
        );
    }

    public function page_clear_cache() {
        echo "<p>Select urls to invalidate:</p><p><textarea></textarea></p><p><input type=\"submit\"/></p>";
    }

    public function page_page_rules() {
        echo "<p>Add new rule:</p>";
        echo "<p>Url: <input type=\"text\"> <select><option>Do not cache</option><option>Cache forever</option></select><button class=\"button\">Add</button></p>";
    }

    public function action_clear_cache_full()
    {
        check_ajax_referer('ultimatecache_clear_cache_full', 'ultimatecache_nonce');
        $result = $this->purge_everything();
        header("Location: " . add_query_arg('ultimatecache-cache-cleared', (int)$result, $_SERVER['HTTP_REFERER']));
    }

    public function save_post()
    {
        add_filter('redirect_post_location', array($this, 'add_notice_query_var'), 99);
    }

    public function add_notice_query_var($location)
    {
        remove_filter('redirect_post_location', array($this, 'add_notice_query_var'), 99);
        if (!is_null($this->failed)) {
            return add_query_arg(array('ultimatecache-cache-cleared' => (int)!$this->failed), $location);
        } else {
            return $location;
        }
    }

    public function cleared_cache_notice()
    {
        if (!empty($_GET['ultimatecache-cache-cleared']) && $_GET['ultimatecache-cache-cleared'] == 1) :
            ?>
            <div class="notice notice-success is-dismissible">
                <p>Cache cleared successfully</p>
            </div>
            <?php
        elseif (isset($_GET['ultimatecache-cache-cleared'])) :
            ?>
            <div class="notice notice-error is-dismissible">
                <p>Failed to clear cache</p>
            </div>
            <?php
        endif;
    }
}
