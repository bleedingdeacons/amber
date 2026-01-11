<?php

declare(strict_types=1);

namespace Amber;

use Amber\Core\AmberServiceProvider;
use Amber\Groups\GroupAdmin;
use Amber\Members\MemberAdmin;
use Amber\Positions\PositionAdmin;
use RuntimeException;
use Unity\Core\DependencyContainer;
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
        $provider = new AmberServiceProvider();
        $provider->register($unityContainer);
        
        self::$initialized = true;

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
}
