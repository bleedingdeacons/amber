<?php

declare(strict_types=1);

// PHPUnit bootstrap.
//
// WordPress stand-ins come from bleedingdeacons/wp-mocks, shared across the
// plugin suite — and largely factored out of the stubs that used to live in
// this directory. Its bootstrap loads Patchwork before anything patchable, so
// anything below that defines WordPress functions of its own must stay after
// the Bootstrap::load() call, not before it.
//
// The `acf` group is loaded because Amber's admin screens read and write ACF
// fields throughout. The `sentinel` group is not: Amber\Logger\HasLogger is
// written to no-op when wp_log() is absent, and that is the branch these tests
// run.
//
// Amber sits on top of sibling plugins: Unity (whose interfaces it consumes)
// and Concordance (whose API client MeetingReconciler reconciles against),
// plus Scrutiny and TSML for Unity. All are loaded from the adjacent plugin
// directories — the same thing WordPress does at runtime — rather than from
// hand-copied stubs, so a change to any contract fails this suite immediately
// instead of going unnoticed until production.
//
// Deliberately not Composer path repositories: those would be hard
// require-dev entries, and `composer install` — a CI gate — fails outright
// when the sibling is absent. CI checks them out alongside before installing.

use BleedingDeacons\WpMocks\Bootstrap;
use BleedingDeacons\WpMocks\Doubles\FakeWpdb;
use BleedingDeacons\WpMocks\WpState;

require_once dirname(__DIR__) . '/vendor/autoload.php';

Bootstrap::load(['wordpress', 'acf']);

// Makes plugins_url()/plugin_dir_url() answer with Amber's own path.
WpState::$pluginSlug = 'amber';

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

// Several admin screens query custom tables through the global $wpdb.
$GLOBALS['wpdb'] = new FakeWpdb();
