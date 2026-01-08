<?php

declare(strict_types=1);

namespace Amber;

use Amber\Core\AmberServiceProvider;
use Amber\Core\DependencyContainer;
use Amber\Groups\GroupAdmin;
use Amber\Members\MemberAdmin;
use Amber\Positions\PositionAdmin;
use RuntimeException;
use function is_admin;

/**
 * Main Amber Plugin Class
 */
class Plugin
{
    private static ?DependencyContainer $container = null;
    private static $unityContainer = null;

    /**
     * Initialize the plugin
     *
     * @param mixed $unityContainer The Unity dependency container
     */
    public static function init($unityContainer = null): void
    {
        self::$unityContainer = $unityContainer;

        if (self::$container === null) {
            self::$container = new DependencyContainer();
            $provider = new AmberServiceProvider();
            $provider->register(self::$container, $unityContainer);
        }

        // Initialize admin services
        if (is_admin()) {
            self::$container->get(PositionAdmin::class);
            self::$container->get(MemberAdmin::class);
            self::$container->get(GroupAdmin::class);
        }
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

    /**
     * Get the Unity container
     *
     * @return mixed
     */
    public static function getUnityContainer()
    {
        return self::$unityContainer;
    }
}
