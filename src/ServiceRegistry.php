<?php

declare(strict_types=1);

namespace JardisCore\Kernel;

use Psr\Container\ContainerInterface;

/**
 * Shared service registry across Domain instances.
 *
 * Implements PSR-11 ContainerInterface for Factory backend compatibility.
 * First-write-wins: once a service is registered, it cannot be overwritten.
 * Null values are accepted but not registered.
 */
class ServiceRegistry implements ContainerInterface
{
    /** @var array<string, object> */
    private array $services = [];

    /**
     * Registers a service. First-write-wins — subsequent calls with the same id are ignored.
     * Null values are silently ignored.
     */
    public function set(string $id, object $service): void
    {
        $this->services[$id] ??= $service;
    }

    public function get(string $id): object
    {
        if (!isset($this->services[$id])) {
            throw new \RuntimeException("Service \"{$id}\" not found in registry.");
        }

        return $this->services[$id];
    }

    public function has(string $id): bool
    {
        return isset($this->services[$id]);
    }
}
