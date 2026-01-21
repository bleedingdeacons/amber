<?php

declare(strict_types=1);

namespace Amber;

use Amber\Groups\GroupAdmin;
use Amber\Managers\IntergroupManager;
use Amber\Members\MemberAdmin;
use Amber\Positions\PositionAdmin;
use Amber\Positions\PositionDashboard;
use RuntimeException;
use Unity\Core\DependencyContainer;
use Unity\Groups\Interfaces\GroupRepositoryInterface;
use Unity\Groups\Interfaces\GroupViewFactoryInterface;
use Unity\Positions\Interfaces\PositionFactoryInterface;
use Unity\Positions\Interfaces\PositionRepositoryInterface;
use Unity\Positions\Interfaces\PositionViewFactoryInterface;
use function is_admin;

/**
 * Main Amber Plugin Class
 */
class Plugin
{
    private static ?DependencyContainer $container = null;
    private static bool $initialized = false;

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
            self::$container->get(PositionAdmin::class);
            self::$container->get(MemberAdmin::class);
            self::$container->get(GroupAdmin::class);
            self::$container->get(PositionDashboard::class);
        }
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
                $c->get(PositionFactoryInterface::class)
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

        // Register Group Admin
        $container->register(GroupAdmin::class, function (DependencyContainer $c) {
            return new GroupAdmin(
                $c->get(GroupViewFactoryInterface::class),
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