<?php

declare(strict_types=1);

/**
 * Plugin Name: Amber
 * Description: Admin components for the Unity intergroup management plugin.
 * Version: 1.2.2
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Author: The Bleeding Deacons
 * Author URI: thebleedingdeacons@gmail.com
 * License: MIT (Modified)
 */

if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
if (!function_exists('get_plugin_data')) {
    require_once(ABSPATH . 'wp-admin/includes/plugin.php');
}
$amber_plugin_data = get_plugin_data(__FILE__, false, false);
define('AMBER_VERSION', $amber_plugin_data['Version']);
define('AMBER_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AMBER_PLUGIN_URL', plugin_dir_url(__FILE__));

// Autoloader for Amber namespace
spl_autoload_register(function ($class) {
    try {
        $prefix = 'Amber\\';
        $base_dir = AMBER_PLUGIN_DIR . 'src/';

        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            return;
        }

        $relative_class = substr($class, $len);
        $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

        if (file_exists($file)) {
            require $file;
        }
    } catch (\Exception $e) {
        error_log('Amber Autoloader Error: ' . $e->getMessage());
    } catch (\Throwable $e) {
        error_log('Amber Autoloader Fatal Error: ' . $e->getMessage());
    }
});

/**
 * Get the Amber dependency container (Unity's container)
 *
 * @return \Unity\Core\DependencyContainer
 * @throws \RuntimeException If Amber is not initialized
 */
function amber(): \Unity\Core\DependencyContainer {
    return \Amber\Plugin::getContainer();
}

// Initialize the plugin after Unity is loaded
add_action('unity_loaded', function($unityContainer) {
    try {
        if (!class_exists('Amber\Plugin')) {
            throw new \Exception('Amber\Plugin class not found. Check that Plugin.php exists in the src/ directory.');
        }

        \Amber\Plugin::init($unityContainer);

        do_action('amber_loaded', \Amber\Plugin::getContainer());

    } catch (\Exception $e) {
        error_log('Amber Plugin Initialization Error: ' . $e->getMessage());
        error_log('Amber Plugin Stack Trace: ' . $e->getTraceAsString());

        if (is_admin()) {
            add_action('admin_notices', function() use ($e) {
                $message = sprintf(
                    '<strong>Amber Plugin Error:</strong> %s',
                    esc_html($e->getMessage())
                );
                echo '<div class="notice notice-error is-dismissible"><p>' . $message . '</p></div>';
            });
        }

        return;

    } catch (\Throwable $e) {
        error_log('Amber Plugin Fatal Error: ' . $e->getMessage());
        error_log('Amber Plugin Stack Trace: ' . $e->getTraceAsString());

        if (is_admin()) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error is-dismissible"><p><strong>Amber Plugin Fatal Error:</strong> Plugin failed to load. Check error logs.</p></div>';
            });
        }

        return;
    }
}, 10);

// Show admin notice if Unity plugin is not active
add_action('admin_notices', function() {
    if (!function_exists('unity') && !did_action('unity_loaded')) {
        echo '<div class="notice notice-warning is-dismissible"><p><strong>Amber:</strong> This plugin requires the Unity plugin to be installed and activated.</p></div>';
    }
});
