<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Tests\Unit;

use JardisCore\Kernel\DomainKernel;
use JardisSupport\Contract\DbConnection\ConnectionPoolInterface;
use JardisSupport\Contract\Filesystem\FilesystemServiceInterface;
use JardisSupport\Contract\Kernel\DomainKernelInterface;
use JardisSupport\Contract\Mailer\MailerInterface;
use JardisSupport\Factory\Factory;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Unit Tests for DomainKernel
 *
 * Focus: Constructor injection, immutability, nullable services, connection support
 */
class DomainKernelTest extends TestCase
{
    public function testImplementsDomainKernelInterface(): void
    {
        $kernel = new DomainKernel('/app/src');

        $this->assertInstanceOf(DomainKernelInterface::class, $kernel);
    }

    public function testConstructorSetsAllServices(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $cache = $this->createMock(CacheInterface::class);
        $logger = $this->createMock(LoggerInterface::class);
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $httpClient = $this->createMock(ClientInterface::class);
        $connection = $this->createMock(ConnectionPoolInterface::class);
        $mailer = $this->createMock(MailerInterface::class);
        $filesystem = $this->createMock(FilesystemServiceInterface::class);

        $kernel = new DomainKernel(
            domainRoot: '/app/src',
            container: $container,
            cache: $cache,
            logger: $logger,
            eventDispatcher: $eventDispatcher,
            httpClient: $httpClient,
            connection: $connection,
            mailer: $mailer,
            filesystem: $filesystem,
            env: ['APP_ENV' => 'test'],
        );

        $this->assertSame('/app/src', $kernel->domainRoot());
        $this->assertSame($cache, $kernel->cache());
        $this->assertSame($logger, $kernel->logger());
        $this->assertSame($eventDispatcher, $kernel->eventDispatcher());
        $this->assertSame($httpClient, $kernel->httpClient());
        $this->assertSame($connection, $kernel->dbConnection());
        $this->assertSame($mailer, $kernel->mailer());
        $this->assertSame($filesystem, $kernel->filesystem());
    }

    public function testNullableServices(): void
    {
        $kernel = new DomainKernel('/app/src');

        $this->assertInstanceOf(ContainerInterface::class, $kernel->container());
        $this->assertNull($kernel->cache());
        $this->assertNull($kernel->logger());
        $this->assertNull($kernel->eventDispatcher());
        $this->assertNull($kernel->httpClient());
        $this->assertNull($kernel->dbConnection());
        $this->assertNull($kernel->mailer());
        $this->assertNull($kernel->filesystem());
    }

    public function testConnectionAcceptsPdo(): void
    {
        $pdo = $this->createMock(PDO::class);

        $kernel = new DomainKernel(
            domainRoot: '/app/src',
            connection: $pdo,
        );

        $this->assertSame($pdo, $kernel->dbConnection());
    }

    public function testConnectionAcceptsConnectionPool(): void
    {
        $pool = $this->createMock(ConnectionPoolInterface::class);

        $kernel = new DomainKernel(
            domainRoot: '/app/src',
            connection: $pool,
        );

        $this->assertSame($pool, $kernel->dbConnection());
    }

    public function testContainerReturnsFactoryInstance(): void
    {
        $kernel = new DomainKernel('/app/src');

        $this->assertInstanceOf(Factory::class, $kernel->container());
    }

    public function testContainerReturnsSameInstance(): void
    {
        $kernel = new DomainKernel('/app/src');

        $first = $kernel->container();
        $second = $kernel->container();

        $this->assertSame($first, $second);
    }

    public function testContainerWrapsExternalContainer(): void
    {
        $external = $this->createMock(ContainerInterface::class);
        $kernel = new DomainKernel('/app/src', container: $external);

        $this->assertInstanceOf(Factory::class, $kernel->container());
    }

    public function testContainerPreservesFactoryIfInjected(): void
    {
        $factory = new Factory();
        $kernel = new DomainKernel('/app/src', container: $factory);

        $this->assertSame($factory, $kernel->container());
    }

    public function testEnvReturnsSingleValue(): void
    {
        $kernel = new DomainKernel('/app/src', env: ['APP_ENV' => 'test']);

        $this->assertSame('test', $kernel->env('APP_ENV'));
    }

    public function testEnvReturnsNullForMissingKey(): void
    {
        $kernel = new DomainKernel('/app/src', env: []);

        $this->assertNull($kernel->env('NONEXISTENT'));
    }

    public function testEnvIsCaseInsensitive(): void
    {
        $kernel = new DomainKernel('/app/src', env: ['DB_HOST' => 'localhost']);

        $this->assertSame('localhost', $kernel->env('db_host'));
        $this->assertSame('localhost', $kernel->env('DB_HOST'));
    }

    public function testEnvFallsBackToGlobalEnv(): void
    {
        $_ENV['test_global_key'] = 'global_value';
        $kernel = new DomainKernel('/app/src');

        $this->assertSame('global_value', $kernel->env('test_global_key'));

        unset($_ENV['test_global_key']);
    }

    public function testEnvPrivateOverridesGlobal(): void
    {
        $_ENV['test_key'] = 'global';
        $kernel = new DomainKernel('/app/src', env: ['TEST_KEY' => 'private']);

        $this->assertSame('private', $kernel->env('test_key'));

        unset($_ENV['test_key']);
    }

    public function testEmptyDomainRootThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('domainRoot must not be empty');

        new DomainKernel('');
    }
}
