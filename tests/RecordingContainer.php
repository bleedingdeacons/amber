<?php

declare(strict_types=1);

namespace Amber\Tests;

use Unity\Core\Interfaces\Container;

/**
 * A tiny in-memory {@see Container} for exercising service registration.
 *
 * It records every factory registered against it and resolves ids through a
 * caller-supplied resolver (typically one that hands back PHPUnit mocks), so a
 * test can register a provider and then invoke the stored factories to prove
 * they build what they claim to.
 */
final class RecordingContainer implements Container
{
    /** @var array<string, callable> */
    public array $factories = [];

    /** @var callable */
    private $resolver;

    /** @param callable $resolver fn(string $id): mixed */
    public function __construct(callable $resolver)
    {
        $this->resolver = $resolver;
    }

    public function register(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
    }

    public function get(string $id): mixed
    {
        if (isset($this->factories[$id])) {
            return ($this->factories[$id])($this);
        }

        return ($this->resolver)($id);
    }

    public function has(string $id): bool
    {
        return isset($this->factories[$id]);
    }

    /** Build the service registered under $id by running its factory. */
    public function build(string $id): mixed
    {
        return ($this->factories[$id])($this);
    }

    /** @return list<string> */
    public function registeredIds(): array
    {
        return array_keys($this->factories);
    }
}
