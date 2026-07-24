<?php

declare(strict_types=1);

// PHPUnit bootstrap.
//
// Amber sits on top of two sibling plugins: Unity (whose interfaces it
// consumes) and Concordance (whose API client MeetingReconciler reconciles
// against). Both are loaded from the adjacent plugin directories — the same
// thing WordPress does at runtime — rather than from hand-copied stubs, so a
// change to either contract fails this suite immediately instead of going
// unnoticed until production.
//
// Deliberately not Composer path repositories: those would be hard
// require-dev entries, and `composer install` — a CI gate — fails outright
// when the sibling is absent. CI checks both out alongside before installing.

require_once dirname(__DIR__) . '/vendor/autoload.php';

if (!defined('ABSPATH')) {
    define('ABSPATH', sys_get_temp_dir() . '/amber-test-wp/');
}

/**
 * Register a PSR-4 autoloader for a sibling plugin's source tree.
 */
$registerSibling = static function (string $prefix, string $pluginDir): void {
    $src = dirname(__DIR__, 2) . '/' . $pluginDir . '/src';

    if (!is_dir($src)) {
        fwrite(STDERR, PHP_EOL . 'ERROR: sibling plugin source not found at ' . $src . PHP_EOL
            . 'Amber is built on it, so it must be checked out as a sibling directory' . PHP_EOL
            . 'for this suite to run.' . PHP_EOL . PHP_EOL);
        exit(1);
    }

    spl_autoload_register(static function (string $class) use ($prefix, $src): void {
        if (!str_starts_with($class, $prefix)) {
            return;
        }

        $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
        $file     = $src . '/' . $relative . '.php';

        if (is_file($file)) {
            require_once $file;
        }
    });
};

$registerSibling('Unity\\', 'unity');
$registerSibling('Concordance\\', 'concordance');
$registerSibling('Scrutiny\\', 'scrutiny');
$registerSibling('TsmlForUnity\\', 'tsml-for-unity');

// WordPress stand-ins. The admin classes call WordPress directly from inside
// long render methods, so the only practical way to exercise them is to make
// those functions real and back them with a store the tests control. See
// tests/stubs/wordpress.php and Amber\Tests\WpState.
//
// Loaded before is_wp_error() below so WP_Error exists for the instanceof.
require_once __DIR__ . '/stubs/wordpress.php';

// MeetingReconciler checks WP_Error results from the Concordance API client.
if (!function_exists('is_wp_error')) {
    function is_wp_error(mixed $thing): bool
    {
        return $thing instanceof \WP_Error;
    }
}

// Several admin screens query custom tables through the global $wpdb.
$GLOBALS['wpdb'] = new \Amber\Tests\FakeWpdb();
