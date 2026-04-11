<?php

declare(strict_types=1);

/**
 * Plugin Name: Amber
 * Description: Admin components for the Unity intergroup management plugin. Requires Scrutiny for GDPR compliance.
 * Version: 1.18.0
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Requires Plugins: sentinel, scrutiny
 * GitHub Plugin URI: https://github.com/thebleedingdeacons/amber
 * GitHub Branch: main
 * Author: The Bleeding Deacons
 * Author URI: https://github.com/bleedingdeacons/amber
 * Contact: thebleedingdeacons@gmail.com, scrutiny
 * License: MIT (Modified)
 */

if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
if (!function_exists('get_plugin_data')) {
    if (file_exists(ABSPATH . 'wp-admin/includes/plugin.php')) {
        require_once(ABSPATH . 'wp-admin/includes/plugin.php');
    }
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
        function_exists('wp_log')
            ? wp_log('amber')->error('Amber Autoloader Error: ' . $e->getMessage(), ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()])
            : error_log('Amber Autoloader Error: ' . $e->getMessage());
    } catch (\Throwable $e) {
        function_exists('wp_log')
            ? wp_log('amber')->critical('Amber Autoloader Fatal Error: ' . $e->getMessage(), ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()])
            : error_log('Amber Autoloader Fatal Error: ' . $e->getMessage());
    }
});

/**
 * Get the Amber dependency container (Unity's container)
 *
 * @return \Psr\Container\ContainerInterface
 * @throws \RuntimeException If Amber is not initialized
 */
function amber(): \Psr\Container\ContainerInterface {
    return \Amber\Plugin::getContainer();
}

// Initialize the plugin after Unity is loaded
add_action('unity/loaded', function($unityContainer) {
    try {
        // Check if Scrutiny is active - Amber requires Scrutiny for GDPR compliance
        if (!function_exists('scrutiny')) {
            throw new \Exception('Scrutiny plugin is required but not active. Please install and activate Scrutiny before using Amber.');
        }

        if (!class_exists('Amber\Plugin')) {
            throw new \Exception('Amber\Plugin class not found. Check that Plugin.php exists in the src/ directory.');
        }

        \Amber\Plugin::init($unityContainer);

        do_action('amber/loaded', \Amber\Plugin::getContainer());

    } catch (\Exception $e) {
        function_exists('wp_log')
            ? wp_log('amber')->error('Amber Plugin Initialization Error: ' . $e->getMessage(), ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()])
            : error_log('Amber Plugin Initialization Error: ' . $e->getMessage());

        if (is_admin()) {
            add_action('admin_notices', function() use ($e) {
                $message = sprintf(
                    '<strong>Amber Plugin Error:</strong> %s',
                    esc_html($e->getMessage())
                );
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($message) . '</p></div>';
            });
        }

        return;

    } catch (\Throwable $e) {
        function_exists('wp_log')
            ? wp_log('amber')->critical('Amber Plugin Fatal Error: ' . $e->getMessage(), ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()])
            : error_log('Amber Plugin Fatal Error: ' . $e->getMessage());

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
    if (!function_exists('unity') && !did_action('unity/loaded')) {
        echo '<div class="notice notice-warning is-dismissible"><p><strong>Amber:</strong> This plugin requires the Unity plugin to be installed and activated.</p></div>';
    } elseif (!function_exists('scrutiny') && function_exists('unity')) {
        echo '<div class="notice notice-error is-dismissible"><p><strong>Amber:</strong> This plugin requires the Scrutiny plugin to be installed and activated for GDPR compliance.</p></div>';
    }
});

// Plugin activation hook - ensure Scrutiny is available
register_activation_hook(__FILE__, function () {
    if (!function_exists('scrutiny')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            esc_html__('Amber requires the Unity and Scrutiny plugins to be installed and activated. Scrutiny must be active to ensure GDPR compliance.', 'amber'),
            esc_html__('Plugin Activation Error', 'amber'),
            ['back_link' => true]
        );
    }
});

// Show warning if Scrutiny gets deactivated while Amber is active
add_action('admin_init', function() {
    if (is_plugin_active(plugin_basename(__FILE__)) && !function_exists('scrutiny')) {
        deactivate_plugins(plugin_basename(__FILE__));
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error"><p><strong>Amber has been deactivated:</strong> The Scrutiny plugin is required for GDPR compliance but is not active.</p></div>';
        });
    }
});