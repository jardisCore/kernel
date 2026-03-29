<?php

declare(strict_types=1);

namespace JardisCore\Domain;

use PDO;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use JardisPort\Domain\DomainKernelInterface;

/**
 * Simple DomainKernel — constructor injection, immutable after creation.
 *
 * All services are optional. Pass what you need, leave the rest null.
 * For Jardis zero-config setup, use jardiscore/foundation's DomainKernelBuilder.
 */
class DomainKernel implements DomainKernelInterface
{
    /**
     * @param array<string, mixed> $env
     */
    public function __construct(
        private readonly string $appRoot,
        private readonly string $domainRoot,
        private readonly ?ContainerInterface $container = null,
        private readonly ?CacheInterface $cache = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        private readonly ?ClientInterface $httpClient = null,
        private readonly ?PDO $dbWriter = null,
        private readonly ?PDO $dbReader = null,
        private readonly array $env = [],
    ) {
    }

    public function appRoot(): string
    {
        return $this->appRoot;
    }

    public function domainRoot(): string
    {
        return $this->domainRoot;
    }

    public function env(?string $key = null): mixed
    {
        if ($key === null) {
            return $this->env;
        }
        return $this->env[$key] ?? null;
    }

    public function container(): ?ContainerInterface
    {
        return $this->container;
    }

    public function cache(): ?CacheInterface
    {
        return $this->cache;
    }

    public function logger(): ?LoggerInterface
    {
        return $this->logger;
    }

    public function eventDispatcher(): ?EventDispatcherInterface
    {
        return $this->eventDispatcher;
    }

    public function httpClient(): ?ClientInterface
    {
        return $this->httpClient;
    }

    public function dbWriter(): ?PDO
    {
        return $this->dbWriter;
    }

    public function dbReader(): ?PDO
    {
        return $this->dbReader ?? $this->dbWriter;
    }
}
