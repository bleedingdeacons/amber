<?php

declare(strict_types=1);

namespace Amber\Core;

use Amber\Groups\GroupAdmin;
use Amber\Members\MemberAdmin;
use Amber\Positions\PositionAdmin;
use Unity\Groups\Interfaces\GroupRepositoryInterface;
use Unity\Groups\Interfaces\GroupViewFactoryInterface;
use Unity\Members\Interfaces\MemberRepositoryInterface;
use Unity\Positions\Interfaces\PositionFactoryInterface;
use Unity\Positions\Interfaces\PositionRepositoryInterface;
use Unity\Positions\Interfaces\PositionViewFactoryInterface;

/**
 * Class AmberServiceProvider
 * 
 * Registers all Amber admin services
 */
class AmberServiceProvider
{
    /**
     * Register all services in the container
     *
     * @param DependencyContainer $container The Amber container
     * @param mixed $unityContainer The Unity container for dependencies
     * @return void
     */
    public function register(DependencyContainer $container, $unityContainer = null): void
    {
        // Register Member Admin
        $container->register(MemberAdmin::class, function (DependencyContainer $c) use ($unityContainer) {
            return new MemberAdmin(
                $unityContainer->get(PositionFactoryInterface::class)
            );
        });

        // Register Position Admin
        $container->register(PositionAdmin::class, function (DependencyContainer $c) use ($unityContainer) {
            return new PositionAdmin(
                $unityContainer->get(PositionViewFactoryInterface::class),
                $unityContainer->get(PositionRepositoryInterface::class)
            );
        });

        // Register Group Admin
        $container->register(GroupAdmin::class, function (DependencyContainer $c) use ($unityContainer) {
            return new GroupAdmin(
                $unityContainer->get(GroupViewFactoryInterface::class),
                $unityContainer->get(GroupRepositoryInterface::class)
            );
        });
    }
}
