<?php

declare(strict_types=1);

namespace JardisCore\Kernel;

use JardisSupport\Contract\ClassVersion\ClassVersionInterface;
use JardisSupport\Contract\DbConnection\ConnectionPoolInterface;
use JardisSupport\Contract\Filesystem\FilesystemServiceInterface;
use JardisSupport\Contract\Kernel\DomainKernelInterface;
use JardisSupport\Contract\Mailer\MailerInterface;
use JardisSupport\ClassVersion\ClassVersion;
use JardisSupport\ClassVersion\Data\ClassVersionConfig;
use JardisSupport\ClassVersion\Reader\LoadClassFromExtensions;
use JardisSupport\ClassVersion\Reader\LoadClassFromProxy;
use JardisSupport\ClassVersion\Support\ClassResolutionCache;
use JardisSupport\DotEnv\DotEnv;
use JardisSupport\Factory\Factory;
use PDO;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use ReflectionClass;
use RuntimeException;

/**
 * Base DomainApp Class
 *
 * Entry point for domain projects. Bootstraps the DomainKernel lazily
 * on first access. Override protected methods to customize services.
 *
 * Services are resolved local-first: if a DomainApp provides a service,
 * it uses its own. Otherwise it falls back to the shared registry.
 * The first DomainApp to provide a service shares it with all others.
 *
 * Subclass this in your domain root directory. The domain root is
 * determined automatically via Reflection on the concrete class.
 */
class DomainApp
{
    private static ?ServiceRegistry $sharedRegistry = null;

    protected ?DomainKernelInterface $kernel = null;
    private ?string $domainRoot = null;
    private ?BoundedContext $boundedContext = null;

    /** @var array<string, mixed>|null */
    private ?array $envData = null;

    /**
     * Returns the DomainKernel, creating it on first access.
     *
     * @throws RuntimeException If bootstrap fails
     */
    final protected function kernel(): DomainKernelInterface
    {
        if ($this->kernel !== null) {
            return $this->kernel;
        }

        try {
            $this->loadEnv();

            $cache = $this->resolve(CacheInterface::class, $this->cache());
            $logger = $this->resolve(LoggerInterface::class, $this->logger());
            $eventDispatcher = $this->resolve(EventDispatcherInterface::class, $this->eventDispatcher());
            $httpClient = $this->resolve(ClientInterface::class, $this->httpClient());
            $connection = $this->resolveConnection($this->dbConnection());
            $mailer = $this->resolve(MailerInterface::class, $this->mailer());
            $filesystem = $this->resolve(FilesystemServiceInterface::class, $this->filesystem());

            $this->kernel = new DomainKernel(
                domainRoot: $this->domainRoot(),
                container: $this->factory(),
                cache: $cache,
                logger: $logger,
                eventDispatcher: $eventDispatcher,
                httpClient: $httpClient,
                connection: $connection,
                mailer: $mailer,
                filesystem: $filesystem,
                env: $this->envData ?? []
            );
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'Kernel bootstrap failed (' . get_class($e) . '): ' . $e->getMessage(),
                0,
                $e
            );
        }

        return $this->kernel;
    }

    /**
     * Resolves and instantiates a class via an internal BoundedContext.
     *
     * Exposes the BoundedContext resolver (Container + ClassVersion) on DomainApp
     * so Domain-level code (e.g. the generated Domain facade) can use the same
     * `$this->handle(X::class)` idiom as BC-level code.
     *
     * @template T
     * @param class-string<T> $className
     * @return T|null
     * @throws \Throwable
     */
    protected function handle(string $className, mixed ...$parameters): mixed
    {
        $this->boundedContext ??= new BoundedContext($this->kernel(), null, $this->version());

        return $this->boundedContext->handle($className, ...$parameters);
    }

    /**
     * Override to activate a Domain-wide ClassVersion (e.g. tenant/feature-flag).
     * Default '' means no version — Extensions/ baseline overrides are picked up,
     * versioned subdirs are skipped.
     */
    protected function version(): string
    {
        return '';
    }

    /**
     * Detects the directory of the concrete subclass via Reflection.
     *
     * @throws RuntimeException If detection fails
     */
    protected function domainRoot(): string
    {
        if ($this->domainRoot === null) {
            $reflection = new ReflectionClass($this);
            $fileName = $reflection->getFileName();

            if ($fileName === false) {
                throw new RuntimeException('Could not determine domain root: reflection failed');
            }

            $this->domainRoot = dirname($fileName);
        }

        return $this->domainRoot;
    }

    /**
     * Returns a single ENV value (case-insensitive, private ENV > $_ENV).
     * Available during hook execution (cache, logger, connection, etc.).
     */
    protected function env(string $key): mixed
    {
        $key = strtolower($key);

        return ($this->envData ?? [])[$key] ?? $_ENV[$key] ?? null;
    }

    /**
     * Override to inject an external DI container (Symfony, PHP-DI, etc.).
     * Replaces the shared registry as Factory backend for this DomainApp only.
     */
    protected function container(): ?ContainerInterface
    {
        return null;
    }

    /**
     * Override to configure ClassVersion labels and fallback chains.
     */
    protected function classVersionConfig(): ClassVersionConfig
    {
        return new ClassVersionConfig();
    }

    /**
     * Builds the ClassVersion instance from config.
     *
     * Uses LoadClassFromExtensions with the Jardis layout convention
     * (root depth 3 = Domain\Bc\Aggregate, injected segment "Extensions").
     * ClassResolutionCache memoizes positive and negative lookups to avoid
     * repeated class_exists() syscalls for missing overrides.
     *
     * Override only if you need a custom ClassVersion setup.
     */
    protected function classVersion(): ClassVersionInterface
    {
        $config = $this->classVersionConfig();

        return new ClassVersion(
            $config,
            new LoadClassFromExtensions(
                depth: 3,
                segmentName: 'Extensions',
                versionConfig: $config,
            ),
            new LoadClassFromProxy($config),
            cache: new ClassResolutionCache(),
        );
    }

    /**
     * Override to provide a PSR-16 cache implementation.
     *
     * @return CacheInterface|false|null
     *   - CacheInterface: use this cache, share it with other DomainApps (first-write-wins)
     *   - null: no local cache, use shared cache from another DomainApp if available
     *   - false: explicitly disable cache for this DomainApp, ignore shared
     */
    protected function cache(): CacheInterface|false|null
    {
        return null;
    }

    /**
     * Override to provide a PSR-3 logger implementation.
     *
     * @return LoggerInterface|false|null
     *   - LoggerInterface: use this logger, share it with other DomainApps (first-write-wins)
     *   - null: no local logger, use shared logger from another DomainApp if available
     *   - false: explicitly disable logger for this DomainApp, ignore shared
     */
    protected function logger(): LoggerInterface|false|null
    {
        return null;
    }

    /**
     * Override to provide a PSR-14 event dispatcher.
     *
     * @return EventDispatcherInterface|false|null
     *   - EventDispatcherInterface: use this dispatcher, share it (first-write-wins)
     *   - null: no local dispatcher, use shared if available
     *   - false: explicitly disable for this DomainApp, ignore shared
     */
    protected function eventDispatcher(): EventDispatcherInterface|false|null
    {
        return null;
    }

    /**
     * Override to provide a PSR-18 HTTP client.
     *
     * @return ClientInterface|false|null
     *   - ClientInterface: use this client, share it (first-write-wins)
     *   - null: no local client, use shared if available
     *   - false: explicitly disable for this DomainApp, ignore shared
     */
    protected function httpClient(): ClientInterface|false|null
    {
        return null;
    }

    /**
     * Override to provide a database connection.
     *
     * @return ConnectionPoolInterface|PDO|false|null
     *   - ConnectionPoolInterface|PDO: use this connection, share it (first-write-wins)
     *   - null: no local connection, use shared if available
     *   - false: explicitly disable for this DomainApp, ignore shared
     */
    protected function dbConnection(): ConnectionPoolInterface|PDO|false|null
    {
        return null;
    }

    /**
     * Override to provide a mailer for sending emails.
     *
     * @return MailerInterface|false|null
     *   - MailerInterface: use this mailer, share it (first-write-wins)
     *   - null: no local mailer, use shared if available
     *   - false: explicitly disable for this DomainApp, ignore shared
     */
    protected function mailer(): MailerInterface|false|null
    {
        return null;
    }

    /**
     * Override to provide a filesystem service.
     *
     * @return FilesystemServiceInterface|false|null
     *   - FilesystemServiceInterface: use this filesystem service, share it (first-write-wins)
     *   - null: no local filesystem service, use shared if available
     *   - false: explicitly disable for this DomainApp, ignore shared
     */
    protected function filesystem(): FilesystemServiceInterface|false|null
    {
        return null;
    }

    /**
     * Returns the shared ServiceRegistry (created once, shared across all DomainApps).
     */
    private static function registry(): ServiceRegistry
    {
        return self::$sharedRegistry ??= new ServiceRegistry();
    }

    /**
     * Resolves a service with three-state logic:
     * - object: use this service and share it (first-write-wins)
     * - null: no local service, fall back to shared registry
     * - false: explicitly disabled, do not use shared fallback
     */
    private function resolve(string $id, mixed $local): mixed
    {
        if ($local === false) {
            return null;
        }

        if ($local !== null) {
            self::registry()->set($id, $local);
            return $local;
        }

        return self::registry()->has($id) ? self::registry()->get($id) : null;
    }

    /**
     * Resolves the connection with three-state logic and type-aware registry keys.
     * - object: use this connection and share it (first-write-wins)
     * - null: no local connection, fall back to shared registry
     * - false: explicitly disabled, do not use shared fallback
     */
    private function resolveConnection(
        ConnectionPoolInterface|PDO|false|null $local,
    ): ConnectionPoolInterface|PDO|null {
        if ($local === false) {
            return null;
        }

        if ($local !== null) {
            if ($local instanceof ConnectionPoolInterface) {
                self::registry()->set(ConnectionPoolInterface::class, $local);
            } else {
                self::registry()->set(PDO::class, $local);
            }
            return $local;
        }

        if (self::registry()->has(ConnectionPoolInterface::class)) {
            /** @var ConnectionPoolInterface */
            return self::registry()->get(ConnectionPoolInterface::class);
        }

        if (self::registry()->has(PDO::class)) {
            /** @var PDO */
            return self::registry()->get(PDO::class);
        }

        return null;
    }

    /**
     * Builds the Factory container with ClassVersion and shared registry as backend.
     */
    private function factory(): ContainerInterface
    {
        return new Factory(
            container: $this->container(),
            instances: [
                ClassVersionInterface::class => $this->classVersion(),
            ],
        );
    }

    /**
     * Loads private ENV from domainRoot/.env. Returns empty array if no file exists.
     *
     * @return void
     * @throws RuntimeException If ENV loading fails
     */
    private function loadEnv(): void
    {
        $root = $this->domainRoot();

        if (!file_exists($root . '/.env')) {
            $this->envData = [];
            return;
        }

        try {
            $this->envData = array_change_key_case((new DotEnv())->loadPrivate($root), CASE_LOWER);
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'Failed to load ENV from ' . $root . ' (' . get_class($e) . '): ' . $e->getMessage(),
                0,
                $e
            );
        }
    }
}
