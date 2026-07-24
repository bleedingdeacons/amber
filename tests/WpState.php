<?php

declare(strict_types=1);

namespace Amber\Tests;

/**
 * The state the WordPress stubs read and write.
 *
 * Amber's admin classes are thin over WordPress: they read options, ACF
 * fields and postmeta, then emit HTML. Testing them therefore means standing
 * up enough of WordPress that a render call runs end to end.
 *
 * Rather than mock every call site, the stubs in stubs/wordpress.php are real
 * functions backed by this one mutable store. A test seeds what a scenario
 * needs — an option, a field value, a post — and asserts on what the class
 * did with it. Everything is static because the stubs are plain functions
 * with nowhere to hold an instance; {@see reset()} clears it between tests.
 */
final class WpState
{
    /** @var array<string, mixed> Option name => value. */
    public static array $options = [];

    /** @var array<string, mixed> "postId|field" => value, read by get_field(). */
    public static array $fields = [];

    /** @var array<int, array<string, mixed>> Post id => meta key => value. */
    public static array $postMeta = [];

    /** @var array<int, object> Post id => post object. */
    public static array $posts = [];

    /** @var array<int, string> Post id => post type. */
    public static array $postTypes = [];

    /** Whether current_user_can() grants anything. */
    public static bool $userCan = true;

    /** Capabilities explicitly denied even when $userCan is true. */
    public static array $deniedCaps = [];

    /** Hooks registered via add_action()/add_filter(), for assertions. */
    public static array $hooks = [];

    /** Shortcodes registered via add_shortcode(). */
    public static array $shortcodes = [];

    /** Scripts/styles enqueued, for assertions. */
    public static array $enqueued = [];

    /** Data passed to wp_localize_script(), keyed by object name. */
    public static array $localized = [];

    /** Dashboard widgets registered. */
    public static array $widgets = [];

    /** Admin menu pages registered. */
    public static array $menus = [];

    /** Submenu pages removed via remove_submenu_page(). */
    public static array $removedSubmenus = [];

    /** Calls to wp_update_post(). */
    public static array $updatedPosts = [];

    /** Redirect targets passed to wp_safe_redirect(). */
    public static array $redirects = [];

    /** The current admin screen returned by get_current_screen(). */
    public static ?object $screen = null;

    /** Rows WP_Query should report as found. */
    public static array $queryPosts = [];

    /** Whether the request is an AJAX request. */
    public static bool $doingAjax = false;

    /** Fixed "now", so time-dependent output is deterministic. */
    public static string $now = '2026-07-24 12:00:00';

    /** Roles the current user holds, reported by wp_get_current_user(). */
    public static array $currentUserRoles = ['administrator'];

    public static function reset(): void
    {
        self::$options = [];
        self::$fields = [];
        self::$postMeta = [];
        self::$posts = [];
        self::$postTypes = [];
        self::$userCan = true;
        self::$deniedCaps = [];
        self::$hooks = [];
        self::$shortcodes = [];
        self::$enqueued = [];
        self::$localized = [];
        self::$widgets = [];
        self::$menus = [];
        self::$removedSubmenus = [];
        self::$updatedPosts = [];
        self::$redirects = [];
        self::$screen = null;
        self::$queryPosts = [];
        self::$doingAjax = false;
        self::$now = '2026-07-24 12:00:00';
        self::$currentUserRoles = ['administrator'];
    }
}

/** Thrown by the stubbed wp_die(), which terminates in production. */
final class WpDieException extends \RuntimeException
{
}

/** Thrown by the stubbed wp_send_json_success()/_error(), which also exit. */
final class JsonResponseException extends \RuntimeException
{
    public function __construct(public readonly bool $success, public readonly mixed $data = null)
    {
        parent::__construct($success ? 'json_success' : 'json_error');
    }
}
