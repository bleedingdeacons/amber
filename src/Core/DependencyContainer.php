<?php

declare(strict_types=1);

namespace Amber\Core;

use RuntimeException;

/**
 * Class DependencyContainer
 * 
 * Simple dependency injection container for Amber
 */
class DependencyContainer
{
    /**
     * @var array<string, mixed>
     */
    private array $services = [];

    /**
     * @var array<string, callable>
     */
    private array $factories = [];

    /**
     * Register a service factory
     *
     * @param string $id Service identifier
     * @param callable $factory Factory callable that creates the service
     * @return void
     */
    public function register(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
    }

    /**
     * Get a service by identifier
     *
     * @param string $id Service identifier
     * @return mixed The service instance
     * @throws RuntimeException If service is not found
     */
    public function get(string $id): mixed
    {
        if (!isset($this->services[$id])) {
            if (!isset($this->factories[$id])) {
                throw new RuntimeException("Service not found: $id");
            }
            $this->services[$id] = $this->factories[$id]($this);
        }
        return $this->services[$id];
    }

    /**
     * Check if a service is registered
     *
     * @param string $id Service identifier
     * @return bool
     */
    public function has(string $id): bool
    {
        return isset($this->factories[$id]) || isset($this->services[$id]);
    }
}
