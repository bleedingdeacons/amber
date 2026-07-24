<?php

declare(strict_types=1);

/**
 * WordPress stand-ins for the Amber test suite.
 *
 * Amber has no WP_Mock dependency, and the admin classes call WordPress
 * directly from inside long render methods — so the practical way to test
 * them is to make the functions real and back them with a store the tests
 * control. Every function here reads or writes {@see Amber\Tests\WpState},
 * which is reset between tests.
 *
 * Escaping and translation helpers pass their input straight through: what
 * is under test is what the page says, not whether WordPress escapes
 * correctly, which is WordPress's own concern.
 *
 * The terminating functions (wp_die, wp_send_json_*) throw instead of
 * exiting, so the guard clauses that call them stay assertable.
 */

use Amber\Tests\JsonResponseException;
use Amber\Tests\WpDieException;
use Amber\Tests\WpState;

// ── Classes ──────────────────────────────────────────────────────────

if (!class_exists('WP_Post')) {
    class WP_Post
    {
        public int $ID = 0;
        public string $post_title = '';
        public string $post_type = 'post';
        public string $post_status = 'publish';
        public string $post_name = '';
        public string $post_content = '';
        public int $post_parent = 0;
        public string $post_modified_gmt = '';

        public function __construct(array|object $data = [])
        {
            foreach ((array) $data as $k => $v) {
                $this->$k = $v;
            }
        }
    }
}

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(
            private string $code = '',
            private string $message = '',
            private mixed $data = null
        ) {
        }

        public function get_error_code(): string
        {
            return $this->code;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }

        public function get_error_data(): mixed
        {
            return $this->data;
        }
    }
}

if (!class_exists('WP_Query')) {
    /**
     * Enough WP_Query for two jobs: the name validators construct one and
     * read ->posts, while the list-table hooks receive one and reshape it
     * through get()/set(). Query vars are therefore readable afterwards, so
     * a test can assert on the meta_query or orderby a hook installed.
     */
    class WP_Query
    {
        public array $posts = [];
        public int $found_posts = 0;
        public array $query_vars = [];

        /** What is_main_query()/is_search() report; set by tests. */
        public bool $isMainQuery = true;
        public bool $isSearch = false;

        public function __construct(array $args = [])
        {
            $this->query_vars = $args;
            $this->posts = WpState::$queryPosts;
            $this->found_posts = count($this->posts);
            WpState::$options['__last_wp_query_args'] = $args;
        }

        public function get(string $key, mixed $default = ''): mixed
        {
            return $this->query_vars[$key] ?? $default;
        }

        public function set(string $key, mixed $value): void
        {
            $this->query_vars[$key] = $value;
        }

        public function is_main_query(): bool
        {
            return $this->isMainQuery;
        }

        public function is_search(): bool
        {
            return $this->isSearch;
        }

        public function have_posts(): bool
        {
            return $this->posts !== [];
        }
    }
}

if (!class_exists('WP_Screen')) {
    class WP_Screen
    {
        public string $id = '';
        public string $base = '';
        public string $post_type = '';

        public function __construct(array $data = [])
        {
            foreach ($data as $k => $v) {
                $this->$k = $v;
            }
        }
    }
}

// ── Escaping and translation ─────────────────────────────────────────

foreach (['esc_html', 'esc_attr', 'esc_url', 'esc_url_raw', 'esc_textarea', 'esc_js', 'wp_kses_post'] as $fn) {
    if (!function_exists($fn)) {
        eval("function {$fn}(\$text = '') { return (string) \$text; }");
    }
}

if (!function_exists('__')) {
    function __(string $text = '', string $domain = ''): string
    {
        return $text;
    }
}

if (!function_exists('esc_html__')) {
    function esc_html__(string $text = '', string $domain = ''): string
    {
        return $text;
    }
}

if (!function_exists('esc_attr__')) {
    function esc_attr__(string $text = '', string $domain = ''): string
    {
        return $text;
    }
}

if (!function_exists('_e')) {
    function _e(string $text = '', string $domain = ''): void
    {
        echo $text;
    }
}

if (!function_exists('esc_html_e')) {
    function esc_html_e(string $text = '', string $domain = ''): void
    {
        echo $text;
    }
}

if (!function_exists('_n')) {
    function _n(string $single, string $plural, int $number, string $domain = ''): string
    {
        return $number === 1 ? $single : $plural;
    }
}

// ── Sanitising ───────────────────────────────────────────────────────

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(mixed $str = ''): string
    {
        return trim(strip_tags((string) $str));
    }
}

if (!function_exists('sanitize_key')) {
    function sanitize_key(string $key = ''): string
    {
        return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key)) ?? '';
    }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash(mixed $value): mixed
    {
        return is_string($value) ? stripslashes($value) : $value;
    }
}

if (!function_exists('absint')) {
    function absint(mixed $n): int
    {
        return abs((int) $n);
    }
}

// ── Options ──────────────────────────────────────────────────────────

if (!function_exists('get_option')) {
    function get_option(string $name, mixed $default = false): mixed
    {
        return array_key_exists($name, WpState::$options) ? WpState::$options[$name] : $default;
    }
}

if (!function_exists('update_option')) {
    function update_option(string $name, mixed $value, mixed $autoload = null): bool
    {
        WpState::$options[$name] = $value;

        return true;
    }
}

if (!function_exists('delete_option')) {
    function delete_option(string $name): bool
    {
        unset(WpState::$options[$name]);

        return true;
    }
}

// ── Posts, meta and ACF ──────────────────────────────────────────────

if (!function_exists('get_post')) {
    function get_post(mixed $id = null): ?WP_Post
    {
        $id = (int) $id;

        return WpState::$posts[$id] ?? null;
    }
}

if (!function_exists('get_post_type')) {
    function get_post_type(mixed $post = null): string|false
    {
        $id = is_object($post) ? (int) ($post->ID ?? 0) : (int) $post;

        return WpState::$postTypes[$id] ?? false;
    }
}

if (!function_exists('get_post_meta')) {
    function get_post_meta(int $postId, string $key = '', bool $single = false): mixed
    {
        $meta = WpState::$postMeta[$postId] ?? [];

        if ($key === '') {
            return $meta;
        }

        $value = $meta[$key] ?? ($single ? '' : []);

        return $single ? $value : (is_array($value) ? $value : [$value]);
    }
}

if (!function_exists('update_post_meta')) {
    function update_post_meta(int $postId, string $key, mixed $value): bool
    {
        WpState::$postMeta[$postId][$key] = $value;

        return true;
    }
}

if (!function_exists('add_post_meta')) {
    function add_post_meta(int $postId, string $key, mixed $value, bool $unique = false): bool
    {
        WpState::$postMeta[$postId][$key] = $value;

        return true;
    }
}

if (!function_exists('delete_post_meta')) {
    function delete_post_meta(int $postId, string $key, mixed $value = ''): bool
    {
        unset(WpState::$postMeta[$postId][$key]);

        return true;
    }
}

if (!function_exists('get_field')) {
    function get_field(string $selector, mixed $postId = false, bool $format = true): mixed
    {
        return WpState::$fields[((int) $postId) . '|' . $selector] ?? null;
    }
}

if (!function_exists('update_field')) {
    function update_field(string $selector, mixed $value, mixed $postId = false): bool
    {
        WpState::$fields[((int) $postId) . '|' . $selector] = $value;

        return true;
    }
}

if (!function_exists('wp_update_post')) {
    function wp_update_post(array $post = [], bool $wpError = false): int
    {
        WpState::$updatedPosts[] = $post;

        return (int) ($post['ID'] ?? 0);
    }
}

if (!function_exists('get_edit_post_link')) {
    function get_edit_post_link(mixed $id = 0, string $context = 'display'): ?string
    {
        return 'https://example.test/wp-admin/post.php?post=' . (int) $id . '&action=edit';
    }
}

// ── Capabilities, screen and request ─────────────────────────────────

if (!function_exists('current_user_can')) {
    function current_user_can(string $cap = '', mixed ...$args): bool
    {
        if (in_array($cap, WpState::$deniedCaps, true)) {
            return false;
        }

        return WpState::$userCan;
    }
}

if (!class_exists('WP_User')) {
    class WP_User
    {
        public int $ID = 1;
        public string $user_login = 'tester';
        public string $display_name = 'Tester';
        /** @var array<int, string> */
        public array $roles;

        public function __construct(array $roles = [])
        {
            $this->roles = $roles;
        }

        public function exists(): bool
        {
            return $this->ID > 0;
        }
    }
}

if (!function_exists('wp_get_current_user')) {
    function wp_get_current_user(): \WP_User
    {
        return new \WP_User(WpState::$currentUserRoles);
    }
}

if (!function_exists('is_admin')) {
    function is_admin(): bool
    {
        return true;
    }
}

if (!function_exists('wp_doing_ajax')) {
    function wp_doing_ajax(): bool
    {
        return WpState::$doingAjax;
    }
}

if (!function_exists('get_current_screen')) {
    function get_current_screen(): ?object
    {
        return WpState::$screen;
    }
}

if (!function_exists('wp_die')) {
    function wp_die(mixed $message = '', mixed $title = '', mixed $args = []): void
    {
        throw new WpDieException(is_string($message) ? $message : 'wp_die');
    }
}

if (!function_exists('wp_send_json_success')) {
    function wp_send_json_success(mixed $data = null, ?int $status = null): void
    {
        throw new JsonResponseException(true, $data);
    }
}

if (!function_exists('wp_send_json_error')) {
    function wp_send_json_error(mixed $data = null, ?int $status = null): void
    {
        throw new JsonResponseException(false, $data);
    }
}

if (!function_exists('wp_safe_redirect')) {
    function wp_safe_redirect(string $location, int $status = 302): bool
    {
        WpState::$redirects[] = $location;

        return true;
    }
}

// ── Nonces ───────────────────────────────────────────────────────────

if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce(string $action = ''): string
    {
        return 'nonce-' . $action;
    }
}

if (!function_exists('wp_nonce_field')) {
    function wp_nonce_field(string $action = '', string $name = '_wpnonce', bool $referer = true, bool $echo = true): string
    {
        $html = '<input type="hidden" name="' . $name . '" value="nonce-' . $action . '" />';
        if ($echo) {
            echo $html;
        }

        return $html;
    }
}

if (!function_exists('check_admin_referer')) {
    function check_admin_referer(string $action = '', string $name = '_wpnonce'): bool
    {
        return true;
    }
}

if (!function_exists('check_ajax_referer')) {
    function check_ajax_referer(string $action = '', mixed $name = false, bool $die = true): bool
    {
        return true;
    }
}

// ── URLs ─────────────────────────────────────────────────────────────

if (!function_exists('admin_url')) {
    function admin_url(string $path = '', string $scheme = 'admin'): string
    {
        return 'https://example.test/wp-admin/' . ltrim($path, '/');
    }
}

if (!function_exists('home_url')) {
    function home_url(string $path = ''): string
    {
        return 'https://example.test/' . ltrim($path, '/');
    }
}

if (!function_exists('plugins_url')) {
    function plugins_url(string $path = '', string $plugin = ''): string
    {
        return 'https://example.test/wp-content/plugins/amber/' . ltrim($path, '/');
    }
}

if (!function_exists('plugin_dir_url')) {
    function plugin_dir_url(string $file = ''): string
    {
        return 'https://example.test/wp-content/plugins/amber/';
    }
}

if (!function_exists('plugin_dir_path')) {
    function plugin_dir_path(string $file = ''): string
    {
        return dirname($file) . '/';
    }
}

if (!function_exists('add_query_arg')) {
    function add_query_arg(mixed ...$args): string
    {
        $base = 'https://example.test/wp-admin/admin.php';

        if (is_array($args[0] ?? null)) {
            $params = $args[0];
            $url = $args[1] ?? $base;
        } else {
            $params = [$args[0] => $args[1] ?? ''];
            $url = $args[2] ?? $base;
        }

        return $url . (str_contains((string) $url, '?') ? '&' : '?') . http_build_query($params);
    }
}

// ── Hooks, menus and assets ──────────────────────────────────────────

if (!function_exists('add_action')) {
    function add_action(string $hook, mixed $callback = null, int $priority = 10, int $accepted = 1): bool
    {
        WpState::$hooks[$hook][] = $callback;

        return true;
    }
}

if (!function_exists('add_filter')) {
    function add_filter(string $hook, mixed $callback = null, int $priority = 10, int $accepted = 1): bool
    {
        WpState::$hooks[$hook][] = $callback;

        return true;
    }
}

if (!function_exists('do_action')) {
    function do_action(string $hook, mixed ...$args): void
    {
        WpState::$hooks['__fired'][] = array_merge([$hook], $args);
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters(string $hook, mixed $value = null, mixed ...$args): mixed
    {
        return $value;
    }
}

if (!function_exists('add_menu_page')) {
    function add_menu_page(
        string $pageTitle = '',
        string $menuTitle = '',
        string $capability = '',
        string $slug = '',
        mixed $callback = null,
        string $icon = '',
        mixed $position = null
    ): string {
        WpState::$menus[] = ['type' => 'menu', 'slug' => $slug, 'title' => $menuTitle, 'cap' => $capability];

        return 'toplevel_page_' . $slug;
    }
}

if (!function_exists('add_submenu_page')) {
    function add_submenu_page(
        string $parent = '',
        string $pageTitle = '',
        string $menuTitle = '',
        string $capability = '',
        string $slug = '',
        mixed $callback = null,
        mixed $position = null
    ): string {
        WpState::$menus[] = [
            'type' => 'submenu', 'parent' => $parent, 'slug' => $slug,
            'title' => $menuTitle, 'cap' => $capability,
        ];

        return $parent . '_page_' . $slug;
    }
}

if (!function_exists('remove_submenu_page')) {
    function remove_submenu_page(string $parent = '', string $slug = ''): mixed
    {
        WpState::$removedSubmenus[] = [$parent, $slug];

        return false;
    }
}

if (!function_exists('wp_add_dashboard_widget')) {
    function wp_add_dashboard_widget(string $id = '', string $name = '', mixed $callback = null): void
    {
        WpState::$widgets[$id] = ['name' => $name, 'callback' => $callback];
    }
}

foreach (['wp_enqueue_script', 'wp_enqueue_style', 'wp_register_script', 'wp_register_style'] as $fn) {
    if (!function_exists($fn)) {
        eval(
            "function {$fn}(\$handle = '', ...\$rest) {"
            . " \\Amber\\Tests\\WpState::\$enqueued[] = ['fn' => '{$fn}', 'handle' => \$handle];"
            . " return true; }"
        );
    }
}

if (!function_exists('wp_localize_script')) {
    function wp_localize_script(string $handle = '', string $objectName = '', array $data = []): bool
    {
        WpState::$localized[$objectName] = $data;

        return true;
    }
}

if (!function_exists('add_shortcode')) {
    function add_shortcode(string $tag = '', mixed $callback = null): void
    {
        WpState::$shortcodes[$tag] = $callback;
    }
}

if (!function_exists('shortcode_exists')) {
    function shortcode_exists(string $tag = ''): bool
    {
        return isset(WpState::$shortcodes[$tag]);
    }
}

if (!function_exists('shortcode_atts')) {
    function shortcode_atts(array $pairs, mixed $atts, string $shortcode = ''): array
    {
        $atts = (array) $atts;
        $out = [];
        foreach ($pairs as $name => $default) {
            $out[$name] = array_key_exists($name, $atts) ? $atts[$name] : $default;
        }

        return $out;
    }
}

// ── Formatting and time ──────────────────────────────────────────────

if (!function_exists('number_format_i18n')) {
    function number_format_i18n(float $number, int $decimals = 0): string
    {
        return number_format($number, $decimals);
    }
}

if (!function_exists('size_format')) {
    function size_format(mixed $bytes, int $decimals = 0): string
    {
        return number_format((float) $bytes, $decimals) . ' B';
    }
}

if (!function_exists('human_time_diff')) {
    function human_time_diff(int $from, int $to = 0): string
    {
        return '5 mins';
    }
}

if (!function_exists('current_time')) {
    function current_time(string $type = 'timestamp', mixed $gmt = 0): mixed
    {
        return $type === 'timestamp' ? strtotime(WpState::$now) : WpState::$now;
    }
}

if (!function_exists('wp_date')) {
    function wp_date(string $format, ?int $timestamp = null, mixed $timezone = null): string
    {
        return date($format, $timestamp ?? strtotime(WpState::$now));
    }
}

if (!function_exists('wp_timezone')) {
    function wp_timezone(): DateTimeZone
    {
        return new DateTimeZone('UTC');
    }
}

if (!function_exists('wpautop')) {
    function wpautop(string $text = '', bool $br = true): string
    {
        return '<p>' . $text . '</p>';
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode(mixed $data, int $options = 0, int $depth = 512): string|false
    {
        return json_encode($data, $options, $depth);
    }
}

// ── Logging (Sentinel bridge; absent by design in tests) ─────────────

if (!function_exists('esc_sql')) {
    function esc_sql(mixed $data): mixed
    {
        return is_string($data) ? addslashes($data) : $data;
    }
}

if (!function_exists('checked')) {
    function checked(mixed $checked, mixed $current = true, bool $echo = true): string
    {
        $html = (string) $checked === (string) $current ? ' checked="checked"' : '';
        if ($echo) {
            echo $html;
        }

        return $html;
    }
}

if (!function_exists('selected')) {
    function selected(mixed $selected, mixed $current = true, bool $echo = true): string
    {
        $html = (string) $selected === (string) $current ? ' selected="selected"' : '';
        if ($echo) {
            echo $html;
        }

        return $html;
    }
}

if (!function_exists('disabled')) {
    function disabled(mixed $disabled, mixed $current = true, bool $echo = true): string
    {
        $html = (string) $disabled === (string) $current ? ' disabled="disabled"' : '';
        if ($echo) {
            echo $html;
        }

        return $html;
    }
}

if (!function_exists('get_the_ID')) {
    function get_the_ID(): int|false
    {
        return WpState::$options['__current_post_id'] ?? false;
    }
}

if (!function_exists('maybe_unserialize')) {
    function maybe_unserialize(mixed $data): mixed
    {
        if (!is_string($data)) {
            return $data;
        }
        $out = @unserialize($data);

        return $out === false && $data !== serialize(false) ? $data : $out;
    }
}

if (!function_exists('is_serialized')) {
    function is_serialized(mixed $data, bool $strict = true): bool
    {
        return is_string($data) && @unserialize($data) !== false;
    }
}

if (!function_exists('get_posts')) {
    function get_posts(array $args = []): array
    {
        WpState::$options['__last_get_posts_args'] = $args;

        return WpState::$queryPosts;
    }
}

if (!function_exists('get_permalink')) {
    function get_permalink(mixed $post = 0): string
    {
        return 'https://example.test/?p=' . (is_object($post) ? ($post->ID ?? 0) : (int) $post);
    }
}

if (!function_exists('get_the_title')) {
    function get_the_title(mixed $post = 0): string
    {
        $id = is_object($post) ? (int) ($post->ID ?? 0) : (int) $post;

        return WpState::$posts[$id]->post_title ?? '';
    }
}

if (!function_exists('wp_kses')) {
    function wp_kses(string $text, mixed $allowed = [], array $protocols = []): string
    {
        return $text;
    }
}

if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags(string $text, bool $break = false): string
    {
        return strip_tags($text);
    }
}

if (!function_exists('wp_trim_words')) {
    function wp_trim_words(string $text, int $num = 55, ?string $more = null): string
    {
        return $text;
    }
}

if (!function_exists('did_action')) {
    function did_action(string $hook): int
    {
        return count(array_filter(
            WpState::$hooks['__fired'] ?? [],
            static fn (array $f): bool => ($f[0] ?? '') === $hook
        ));
    }
}

if (!function_exists('has_action')) {
    function has_action(string $hook, mixed $callback = false): bool
    {
        return isset(WpState::$hooks[$hook]);
    }
}

if (!function_exists('remove_action')) {
    function remove_action(string $hook, mixed $callback = null, int $priority = 10): bool
    {
        unset(WpState::$hooks[$hook]);

        return true;
    }
}

if (!function_exists('do_shortcode')) {
    function do_shortcode(string $content = ''): string
    {
        return $content;
    }
}

if (!function_exists('is_user_logged_in')) {
    function is_user_logged_in(): bool
    {
        return true;
    }
}

if (!function_exists('wp_parse_args')) {
    function wp_parse_args(mixed $args, array $defaults = []): array
    {
        return array_merge($defaults, (array) $args);
    }
}
