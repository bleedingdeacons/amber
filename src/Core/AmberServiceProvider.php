<?php

declare(strict_types=1);

namespace Amber\Core;

use Amber\Groups\GroupAdmin;
use Amber\Members\MemberAdmin;
use Amber\Positions\PositionAdmin;
use Unity\Core\DependencyContainer;
use Unity\Groups\Interfaces\GroupRepositoryInterface;
use Unity\Groups\Interfaces\GroupViewFactoryInterface;
use Unity\Positions\Interfaces\PositionFactoryInterface;
use Unity\Positions\Interfaces\PositionRepositoryInterface;
use Unity\Positions\Interfaces\PositionViewFactoryInterface;

/**
 * Class AmberServiceProvider
 * 
 * Registers all Amber admin services with Unity's container
 */
class AmberServiceProvider
{
    /**
     * Register all services in Unity's container
     *
     * @param DependencyContainer $container The Unity dependency container
     * @return void
     */
    public function register(DependencyContainer $container): void
    {
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

        // Register Group Admin
        $container->register(GroupAdmin::class, function (DependencyContainer $c) {
            return new GroupAdmin(
                $c->get(GroupViewFactoryInterface::class),
                $c->get(GroupRepositoryInterface::class)
            );
        });
    }
}
