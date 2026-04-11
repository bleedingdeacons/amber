<?php

declare(strict_types=1);

namespace Amber;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Amber\Admin\DeveloperDashboard;
use Amber\Admin\IntergroupMeetings\IntergroupMeetingAdmin;
use Amber\Admin\IntergroupMeetings\IntergroupMeetingAttendanceDashboard;
use Amber\Admin\IntergroupMeetings\IntergroupMeetingDashboard;
use Amber\Admin\Meetings\MeetingAdmin;
use Amber\Admin\Meetings\MeetingDashboard;
use Amber\Admin\Members\DirectoryDashboard;
use Amber\Admin\Members\MemberAdmin;
use Amber\Admin\Members\AnonymousNameValidator;
use Amber\Admin\Positions\PositionAdmin;
use Amber\Admin\Positions\PositionDashboard;
use Amber\Admin\Positions\PositionNameValidator;
use Amber\Core\AmberServiceProvider;
use Amber\Core\MenuRegistrar;
use Amber\Managers\IntergroupManager;
use Amber\Managers\PositionShortcodeRenderer;
use Psr\Container\ContainerInterface;
use Unity\Core\Interfaces\Container;
use Unity\Members\Interfaces\MemberChangeTracker;

use RuntimeException;

use function add_action;
use function is_admin;

/**
 * Main Amber Plugin Class
 *
 * Orchestrates plugin lifecycle: service registration, admin initialisation,
 * and version-gated migrations. Service registration is delegated to
 * AmberServiceProvider; menu structure to MenuRegistrar; help page rendering
 * to HelpPage.
 */
class Plugin
{
    use \Amber\Logger\HasLogger;

    protected static function logChannel(): string
    {
        return 'amber';
    }

    private static ?ContainerInterface $container = null;
    private static bool $initialized = false;

    /**
     * Legacy constants — kept for backward compatibility with any code
     * referencing Plugin::MENU_SLUG or Plugin::MENU_CAPABILITY.
     */
    public const MENU_SLUG = MenuRegistrar::MENU_SLUG;
    public const MENU_CAPABILITY = MenuRegistrar::MENU_CAPABILITY;

    private static ?MemberChangeTracker $memberChangeTracker = null;

    /**
     * Initialize the plugin
     *
     * @param Container $unityContainer The Unity dependency container
     */
    public static function init(Container $unityContainer): void
    {
        if (self::$initialized) {
            return;
        }

        self::$container = $unityContainer;

        (new AmberServiceProvider())->register($unityContainer);

        self::$initialized = true;

        // Initialize IntergroupManager (hooks and meta updates)
        self::$container->get(IntergroupManager::class);

        // Initialize shortcode renderer (always needed for front-end shortcodes)
        self::$container->get(PositionShortcodeRenderer::class);

        // Initialize admin services
        if (is_admin()) {
            add_action('admin_menu', [MenuRegistrar::class, 'registerMenus']);
            add_action('admin_menu', [MenuRegistrar::class, 'registerHelpMenu'], 999);

            self::$container->get(PositionAdmin::class);
            self::$container->get(PositionNameValidator::class);
            self::$container->get(MemberAdmin::class);
            self::$container->get(AnonymousNameValidator::class);
            self::$container->get(MeetingAdmin::class);
            self::$container->get(IntergroupMeetingAdmin::class);
            self::$container->get(PositionDashboard::class);
            self::$container->get(MeetingDashboard::class);
            self::$container->get(DirectoryDashboard::class);

            // Run metadata migrations once per version upgrade (deferred to admin_init
            // so that $wp_rewrite and other globals are available)
            add_action('admin_init', [self::class, 'maybeRunMigrations']);
            self::$container->get(IntergroupMeetingDashboard::class);
            self::$container->get(IntergroupMeetingAttendanceDashboard::class);
            self::$container->get(DeveloperDashboard::class);
        }

        self::logDebug('Initialised', ['version' => defined('AMBER_VERSION') ? AMBER_VERSION : 'unknown']);
    }

    /**
     * Run one-off metadata migrations when the plugin version changes.
     *
     * Compares the stored version against AMBER_VERSION and, on mismatch,
     * regenerates all position and member sort-key metadata so that column sorting
     * works correctly without a manual WP-CLI step after deployment.
     */
    public static function maybeRunMigrations(): void
    {
        $optionKey      = 'amber_db_version';
        $currentVersion = defined('AMBER_VERSION') ? AMBER_VERSION : '0.0.0';
        $storedVersion  = get_option($optionKey, '');

        if ($storedVersion === $currentVersion) {
            return;
        }

        try {
            /** @var PositionAdmin $positionAdmin */
            $positionAdmin = self::$container->get(PositionAdmin::class);
            $positionCount = $positionAdmin->setupAllPositionsMetadata();

            /** @var MemberAdmin $memberAdmin */
            $memberAdmin = self::$container->get(MemberAdmin::class);
            $memberCount = $memberAdmin->setupAllMembersMetadata();

            self::logInfo('Amber migration complete', [
                'from'              => $storedVersion ?: '(none)',
                'to'                => $currentVersion,
                'positions_updated' => (string) $positionCount,
                'members_updated'   => (string) $memberCount,
            ]);
        } catch (\Throwable $e) {
            self::logError('Amber migration failed', [
                'error' => $e->getMessage(),
            ]);
        }

        update_option($optionKey, $currentVersion);
    }

    /**
     * Get the dependency container
     *
     * @return ContainerInterface
     * @throws RuntimeException If plugin is not initialized
     */
    public static function getContainer(): ContainerInterface
    {
        if (self::$container === null) {
            throw new RuntimeException('Amber Plugin not initialized');
        }
        return self::$container;
    }
}