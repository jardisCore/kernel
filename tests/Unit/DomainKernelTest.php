<?php

declare(strict_types=1);

namespace JardisCore\Domain\Tests\Unit;

use JardisCore\Domain\DomainKernel;
use JardisPort\Domain\DomainKernelInterface;
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
 * Focus: Constructor injection, immutability, nullable services, dbReader fallback
 */
class DomainKernelTest extends TestCase
{
    public function testImplementsDomainKernelInterface(): void
    {
        $kernel = new DomainKernel('/app', '/app/src');

        $this->assertInstanceOf(DomainKernelInterface::class, $kernel);
    }

    public function testConstructorSetsAllServices(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $cache = $this->createMock(CacheInterface::class);
        $logger = $this->createMock(LoggerInterface::class);
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $httpClient = $this->createMock(ClientInterface::class);
        $dbWriter = $this->createMock(PDO::class);
        $dbReader = $this->createMock(PDO::class);

        $kernel = new DomainKernel(
            appRoot: '/app',
            domainRoot: '/app/src',
            container: $container,
            cache: $cache,
            logger: $logger,
            eventDispatcher: $eventDispatcher,
            httpClient: $httpClient,
            dbWriter: $dbWriter,
            dbReader: $dbReader,
            env: ['APP_ENV' => 'test'],
        );

        $this->assertSame('/app', $kernel->appRoot());
        $this->assertSame('/app/src', $kernel->domainRoot());
        $this->assertSame($container, $kernel->container());
        $this->assertSame($cache, $kernel->cache());
        $this->assertSame($logger, $kernel->logger());
        $this->assertSame($eventDispatcher, $kernel->eventDispatcher());
        $this->assertSame($httpClient, $kernel->httpClient());
        $this->assertSame($dbWriter, $kernel->dbWriter());
        $this->assertSame($dbReader, $kernel->dbReader());
    }

    public function testNullableServices(): void
    {
        $kernel = new DomainKernel('/app', '/app/src');

        $this->assertNull($kernel->container());
        $this->assertNull($kernel->cache());
        $this->assertNull($kernel->logger());
        $this->assertNull($kernel->eventDispatcher());
        $this->assertNull($kernel->httpClient());
        $this->assertNull($kernel->dbWriter());
        $this->assertNull($kernel->dbReader());
    }

    public function testDbReaderFallsBackToWriter(): void
    {
        $dbWriter = $this->createMock(PDO::class);

        $kernel = new DomainKernel(
            appRoot: '/app',
            domainRoot: '/app/src',
            dbWriter: $dbWriter,
        );

        $this->assertSame($dbWriter, $kernel->dbReader());
    }

    public function testDbReaderReturnsReaderWhenSet(): void
    {
        $dbWriter = $this->createMock(PDO::class);
        $dbReader = $this->createMock(PDO::class);

        $kernel = new DomainKernel(
            appRoot: '/app',
            domainRoot: '/app/src',
            dbWriter: $dbWriter,
            dbReader: $dbReader,
        );

        $this->assertSame($dbReader, $kernel->dbReader());
        $this->assertNotSame($dbWriter, $kernel->dbReader());
    }

    public function testGetEnvReturnsAll(): void
    {
        $env = ['APP_ENV' => 'test', 'DB_HOST' => 'localhost'];

        $kernel = new DomainKernel('/app', '/app/src', env: $env);

        $this->assertSame($env, $kernel->env());
    }

    public function testGetEnvReturnsSingle(): void
    {
        $kernel = new DomainKernel('/app', '/app/src', env: ['APP_ENV' => 'test']);

        $this->assertSame('test', $kernel->env('APP_ENV'));
    }

    public function testGetEnvReturnsNullForMissingKey(): void
    {
        $kernel = new DomainKernel('/app', '/app/src', env: []);

        $this->assertNull($kernel->env('NONEXISTENT'));
    }

    public function testImmutableAfterConstruction(): void
    {
        $reflection = new \ReflectionClass(DomainKernel::class);

        foreach ($reflection->getProperties() as $property) {
            $this->assertTrue(
                $property->isReadOnly(),
                "Property {$property->getName()} should be readonly"
            );
        }
    }
}
