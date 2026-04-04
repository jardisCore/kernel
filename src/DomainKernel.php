<?php

declare(strict_types=1);

namespace JardisCore\Kernel;

use JardisSupport\Contract\DbConnection\ConnectionPoolInterface;
use JardisSupport\Contract\Filesystem\FilesystemServiceInterface;
use JardisSupport\Contract\Kernel\DomainKernelInterface;
use JardisSupport\Contract\Mailer\MailerInterface;
use JardisSupport\Factory\Factory;
use PDO;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Simple DomainKernel — constructor injection, immutable after creation.
 *
 * All services are optional. Pass what you need, leave the rest null.
 * If no container is provided, a bare Factory is returned as fallback.
 */
class DomainKernel implements DomainKernelInterface
{
    private readonly Factory $factory;

    /** @var array<string, mixed> */
    private readonly array $env;

    /** @param array<string, mixed> $env */
    public function __construct(
        private readonly string $domainRoot,
        ?ContainerInterface $container = null,
        private readonly ?CacheInterface $cache = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        private readonly ?ClientInterface $httpClient = null,
        private readonly ConnectionPoolInterface|PDO|null $connection = null,
        private readonly ?MailerInterface $mailer = null,
        private readonly ?FilesystemServiceInterface $filesystem = null,
        array $env = [],
    ) {
        if ($domainRoot === '') {
            throw new \InvalidArgumentException('domainRoot must not be empty');
        }

        $this->env     = array_change_key_case($env, CASE_LOWER);
        $this->factory = $container instanceof Factory ? $container : new Factory($container);
    }

    public function domainRoot(): string
    {
        return $this->domainRoot;
    }

    public function env(string $key): mixed
    {
        $key = strtolower($key);
        return $this->env[$key] ?? $_ENV[$key] ?? null;
    }

    public function container(): Factory
    {
        return $this->factory;
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

    public function dbConnection(): ConnectionPoolInterface|PDO|null
    {
        return $this->connection;
    }

    public function mailer(): ?MailerInterface
    {
        return $this->mailer;
    }

    public function filesystem(): ?FilesystemServiceInterface
    {
        return $this->filesystem;
    }
}
