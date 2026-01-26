<?php

declare(strict_types=1);

namespace Amber;

use Amber\Admin\Meetings\MeetingAdmin;
use Amber\Admin\Members\MemberAdmin;
use Amber\Admin\Positions\PositionAdmin;
use Amber\Admin\Positions\PositionDashboard;
use Amber\Managers\IntergroupManager;
use Unity\Core\DependencyContainer;
use Unity\Groups\Interfaces\GroupFactoryInterface;
use Unity\Groups\Interfaces\GroupRepositoryInterface;
use Unity\Members\Interfaces\MemberRepositoryInterface;
use Unity\Positions\Interfaces\PositionFactoryInterface;
use Unity\Positions\Interfaces\PositionRepositoryInterface;
use Unity\Positions\Interfaces\PositionViewFactoryInterface;
use RuntimeException;

use function add_action;
use function add_menu_page;
use function add_submenu_page;
use function is_admin;
use function remove_submenu_page;

/**
 * Main Amber Plugin Class
 */
class Plugin
{
    private static ?DependencyContainer $container = null;
    private static bool $initialized = false;

    public const MENU_SLUG = 'intergroup';
    public const MENU_CAPABILITY = 'edit_posts';

    /**
     * Initialize the plugin
     *
     * @param DependencyContainer $unityContainer The Unity dependency container
     */
    public static function init(DependencyContainer $unityContainer): void
    {
        if (self::$initialized) {
            return;
        }

        self::$container = $unityContainer;

        // Register Amber services with Unity's container
        self::registerServices($unityContainer);

        self::$initialized = true;

        // Initialize IntergroupManager (always needed for shortcodes)
        self::$container->get(IntergroupManager::class);

        // Initialize admin services
        if (is_admin()) {
            add_action('admin_menu', [self::class, 'registerMenus']);

            self::$container->get(PositionAdmin::class);
            self::$container->get(MemberAdmin::class);
            self::$container->get(MeetingAdmin::class);
            self::$container->get(PositionDashboard::class);
        }
    }

    /**
     * Register the Intergroup admin menu and sub-menus
     */
    public static function registerMenus(): void
    {
        // Add main Intergroup menu
        add_menu_page(
            'Intergroup',
            'Intergroup',
            self::MENU_CAPABILITY,
            self::MENU_SLUG,
            '',
            'dashicons-admin-multisite',
            3
        );

        // Add Positions sub-menu
        add_submenu_page(
            self::MENU_SLUG,
            'Positions',
            'Positions',
            self::MENU_CAPABILITY,
            'edit.php?post_type=intergroup-position'
        );

        // Add Members sub-menu
        add_submenu_page(
            self::MENU_SLUG,
            'Members',
            'Members',
            self::MENU_CAPABILITY,
            'edit.php?post_type=intergroup-member'
        );

        // Add Meetings sub-menu
        add_submenu_page(
            self::MENU_SLUG,
            'Meetings',
            'Groups / Meetings',
            self::MENU_CAPABILITY,
            'edit.php?post_type=tsml_meeting'
        );

        // Remove the default Intergroup submenu item
        remove_submenu_page(self::MENU_SLUG, self::MENU_SLUG);
    }

    /**
     * Register all Amber services in Unity's container
     *
     * @param DependencyContainer $container The Unity dependency container
     * @return void
     */
    private static function registerServices(DependencyContainer $container): void
    {

        // Register Intergroup Manager
        $container->register(IntergroupManager::class, function (DependencyContainer $c) {
            return new IntergroupManager(
                $c->get(PositionViewFactoryInterface::class)
            );
        });

        // Register Member Admin
        $container->register(MemberAdmin::class, function (DependencyContainer $c) {
            return new MemberAdmin(
                $c->get(PositionFactoryInterface::class),
                $c->get(MemberRepositoryInterface::class),
                $c->get(GroupFactoryInterface::class)
            );
        });

        // Register Position Admin
        $container->register(PositionAdmin::class, function (DependencyContainer $c) {
            return new PositionAdmin(
                $c->get(PositionViewFactoryInterface::class),
                $c->get(PositionRepositoryInterface::class)
            );
        });

        // Register Position Dashboard
        $container->register(PositionDashboard::class, function (DependencyContainer $c) {
            return new PositionDashboard(
                $c->get(PositionViewFactoryInterface::class),
                $c->get(PositionRepositoryInterface::class)
            );
        });

        // Register Meeting Admin
        $container->register(MeetingAdmin::class, function (DependencyContainer $c) {
            return new MeetingAdmin(
                $c->get(GroupRepositoryInterface::class)
            );
        });
    }

    /**
     * Get the dependency container
     *
     * @return DependencyContainer
     * @throws RuntimeException If plugin is not initialized
     */
    public static function getContainer(): DependencyContainer
    {
        if (self::$container === null) {
            throw new RuntimeException('Amber Plugin not initialized');
        }
        return self::$container;
    }
}