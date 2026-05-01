<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Tests\Unit;

use JardisCore\Kernel\BoundedContext;
use JardisCore\Kernel\DomainApp;
use JardisCore\Kernel\ServiceRegistry;
use JardisSupport\Contract\ClassVersion\ClassVersionInterface;
use JardisSupport\Contract\Kernel\DomainKernelInterface;
use JardisSupport\ClassVersion\Data\ClassVersionConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;
use stdClass;

/**
 * Unit Tests for DomainApp
 *
 * Focus: Lazy kernel bootstrap, domainRoot detection, ClassVersion,
 *        service resolution (local/shared/false), ServiceRegistry
 */
class DomainAppTest extends TestCase
{
    protected function setUp(): void
    {
        // Reset shared registry between tests
        $reflection = new \ReflectionClass(DomainApp::class);
        $prop = $reflection->getProperty('sharedRegistry');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    public function testKernelIsLazyCreated(): void
    {
        $domain = new DomainApp();

        $kernel = $this->invokeKernel($domain);

        $this->assertInstanceOf(DomainKernelInterface::class, $kernel);
    }

    public function testKernelReturnsSameInstance(): void
    {
        $domain = new DomainApp();

        $first = $this->invokeKernel($domain);
        $second = $this->invokeKernel($domain);

        $this->assertSame($first, $second);
    }

    public function testDomainRootIsDetected(): void
    {
        $domain = new DomainApp();

        $reflection = new \ReflectionMethod($domain, 'domainRoot');
        $reflection->setAccessible(true);

        $root = $reflection->invoke($domain);

        $this->assertIsString($root);
        $this->assertDirectoryExists($root);
    }

    public function testDomainRootIsCached(): void
    {
        $domain = new DomainApp();

        $reflection = new \ReflectionMethod($domain, 'domainRoot');
        $reflection->setAccessible(true);

        $first = $reflection->invoke($domain);
        $second = $reflection->invoke($domain);

        $this->assertSame($first, $second);
    }

    public function testKernelHasDomainRoot(): void
    {
        $domain = new DomainApp();

        $kernel = $this->invokeKernel($domain);

        $this->assertNotEmpty($kernel->domainRoot());
    }

    public function testKernelHasClassVersionInContainer(): void
    {
        $domain = new DomainApp();

        $kernel = $this->invokeKernel($domain);

        $this->assertTrue($kernel->container()->has(ClassVersionInterface::class));
    }

    public function testClassVersionIsRetrievable(): void
    {
        $domain = new DomainApp();

        $kernel = $this->invokeKernel($domain);
        $cv = $kernel->container()->get(ClassVersionInterface::class);

        $this->assertInstanceOf(ClassVersionInterface::class, $cv);
    }

    public function testClassVersionConfigReturnsDefault(): void
    {
        $domain = new DomainApp();

        $reflection = new \ReflectionMethod($domain, 'classVersionConfig');
        $reflection->setAccessible(true);

        $this->assertInstanceOf(ClassVersionConfig::class, $reflection->invoke($domain));
    }

    public function testContainerHookReturnsNullByDefault(): void
    {
        $domain = new DomainApp();

        $reflection = new \ReflectionMethod($domain, 'container');
        $reflection->setAccessible(true);

        $this->assertNull($reflection->invoke($domain));
    }

    public function testServiceMethodsReturnNullByDefault(): void
    {
        $domain = new DomainApp();
        $reflection = new \ReflectionClass($domain);

        foreach (['cache', 'logger', 'eventDispatcher', 'httpClient', 'dbConnection', 'mailer', 'filesystem'] as $method) {
            $m = $reflection->getMethod($method);
            $m->setAccessible(true);
            $this->assertNull($m->invoke($domain), "{$method}() should return null");
        }
    }

    public function testKernelServicesAreNullByDefault(): void
    {
        $domain = new DomainApp();

        $kernel = $this->invokeKernel($domain);

        $this->assertNull($kernel->cache());
        $this->assertNull($kernel->logger());
        $this->assertNull($kernel->eventDispatcher());
        $this->assertNull($kernel->httpClient());
        $this->assertNull($kernel->dbConnection());
        $this->assertNull($kernel->mailer());
        $this->assertNull($kernel->filesystem());
    }

    public function testLoadEnvReturnsEmptyWhenNoEnvFile(): void
    {
        $domain = new DomainApp();

        $kernel = $this->invokeKernel($domain);

        $this->assertNull($kernel->env('nonexistent_key'));
    }

    public function testKernelBootstrapFailureWrapsException(): void
    {
        $domain = new class extends DomainApp {
            protected function domainRoot(): string
            {
                throw new \Exception('Simulated failure');
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Kernel bootstrap failed (Exception): Simulated failure');

        $this->invokeKernel($domain);
    }

    public function testKernelIsFinal(): void
    {
        $reflection = new \ReflectionMethod(DomainApp::class, 'kernel');

        $this->assertTrue($reflection->isFinal());
    }

    // =========================================================================
    // Service sharing: local / shared / false
    // =========================================================================

    public function testLocalServiceIsUsed(): void
    {
        $cache = $this->createMock(CacheInterface::class);

        $domain = new class ($cache) extends DomainApp {
            public function __construct(private CacheInterface $myCache)
            {
            }

            protected function cache(): CacheInterface|false|null
            {
                return $this->myCache;
            }
        };

        $kernel = $this->invokeKernel($domain);

        $this->assertSame($cache, $kernel->cache());
    }

    public function testLocalServiceIsSharedToSecondDomain(): void
    {
        $cache = $this->createMock(CacheInterface::class);

        $domainA = new class ($cache) extends DomainApp {
            public function __construct(private CacheInterface $myCache)
            {
            }

            protected function cache(): CacheInterface|false|null
            {
                return $this->myCache;
            }
        };

        $domainB = new DomainApp();

        // Domain A bootstraps first, registers cache in shared registry
        $kernelA = $this->invokeKernel($domainA);
        // Domain B has no local cache, gets shared one
        $kernelB = $this->invokeKernel($domainB);

        $this->assertSame($cache, $kernelA->cache());
        $this->assertSame($cache, $kernelB->cache());
    }

    public function testFalseDisablesSharedFallback(): void
    {
        $cache = $this->createMock(CacheInterface::class);

        $domainA = new class ($cache) extends DomainApp {
            public function __construct(private CacheInterface $myCache)
            {
            }

            protected function cache(): CacheInterface|false|null
            {
                return $this->myCache;
            }
        };

        $domainB = new class extends DomainApp {
            protected function cache(): CacheInterface|false|null
            {
                return false;
            }
        };

        $this->invokeKernel($domainA);
        $kernelB = $this->invokeKernel($domainB);

        $this->assertNull($kernelB->cache());
    }

    public function testFirstWriteWins(): void
    {
        $cacheA = $this->createMock(CacheInterface::class);
        $cacheB = $this->createMock(CacheInterface::class);

        $domainA = new class ($cacheA) extends DomainApp {
            public function __construct(private CacheInterface $myCache)
            {
            }

            protected function cache(): CacheInterface|false|null
            {
                return $this->myCache;
            }
        };

        $domainB = new class ($cacheB) extends DomainApp {
            public function __construct(private CacheInterface $myCache)
            {
            }

            protected function cache(): CacheInterface|false|null
            {
                return $this->myCache;
            }
        };

        $this->invokeKernel($domainA);
        $this->invokeKernel($domainB);

        // Both get cache A — first write wins in the shared registry
        // But domain B provided its own local cache, so it uses that
        $kernelB = $this->invokeKernel($domainB);
        $this->assertSame($cacheB, $kernelB->cache());
    }

    public function testSharedRegistryFirstWriteWinsForNullDomains(): void
    {
        $cache = $this->createMock(CacheInterface::class);

        $domainA = new class ($cache) extends DomainApp {
            public function __construct(private CacheInterface $myCache)
            {
            }

            protected function cache(): CacheInterface|false|null
            {
                return $this->myCache;
            }
        };

        // Two domains with null cache
        $domainB = new DomainApp();
        $domainC = new DomainApp();

        $this->invokeKernel($domainA);
        $kernelB = $this->invokeKernel($domainB);
        $kernelC = $this->invokeKernel($domainC);

        // Both B and C get A's cache from shared registry
        $this->assertSame($cache, $kernelB->cache());
        $this->assertSame($cache, $kernelC->cache());
    }

    // =========================================================================
    // ServiceRegistry
    // =========================================================================

    public function testServiceRegistrySetAndGet(): void
    {
        $registry = new ServiceRegistry();
        $logger = $this->createMock(LoggerInterface::class);

        $registry->set(LoggerInterface::class, $logger);

        $this->assertTrue($registry->has(LoggerInterface::class));
        $this->assertSame($logger, $registry->get(LoggerInterface::class));
    }

    public function testServiceRegistryFirstWriteWins(): void
    {
        $registry = new ServiceRegistry();
        $loggerA = $this->createMock(LoggerInterface::class);
        $loggerB = $this->createMock(LoggerInterface::class);

        $registry->set(LoggerInterface::class, $loggerA);
        $registry->set(LoggerInterface::class, $loggerB);

        $this->assertSame($loggerA, $registry->get(LoggerInterface::class));
    }

    public function testServiceRegistryRejectsNonObject(): void
    {
        $registry = new ServiceRegistry();

        $this->expectException(\TypeError::class);

        /** @phpstan-ignore argument.type */
        $registry->set(LoggerInterface::class, null);
    }

    public function testServiceRegistryGetThrowsOnMissing(): void
    {
        $registry = new ServiceRegistry();

        $this->expectException(RuntimeException::class);

        $registry->get('NonExistent');
    }

    // =========================================================================
    // handle() + version() on DomainApp
    // =========================================================================

    public function testVersionDefaultsToEmptyString(): void
    {
        $domain = new DomainApp();

        $reflection = new \ReflectionMethod($domain, 'version');
        $reflection->setAccessible(true);

        $this->assertSame('', $reflection->invoke($domain));
    }

    public function testVersionCanBeOverridden(): void
    {
        $domain = new class extends DomainApp {
            protected function version(): string
            {
                return 'v2';
            }
        };

        $reflection = new \ReflectionMethod($domain, 'version');
        $reflection->setAccessible(true);

        $this->assertSame('v2', $reflection->invoke($domain));
    }

    public function testHandleResolvesClassViaInternalBoundedContext(): void
    {
        $domain = new DomainApp();

        $result = $this->invokeHandle($domain, stdClass::class);

        $this->assertInstanceOf(stdClass::class, $result);
    }

    public function testHandleReusesInternalBoundedContext(): void
    {
        $domain = new DomainApp();

        $this->invokeHandle($domain, stdClass::class);
        $this->invokeHandle($domain, stdClass::class);

        $reflection = new \ReflectionProperty(DomainApp::class, 'boundedContext');
        $reflection->setAccessible(true);

        $this->assertInstanceOf(BoundedContext::class, $reflection->getValue($domain));
    }

    public function testHandlePassesVersionToBoundedContext(): void
    {
        $proxyObject = new stdClass();
        $proxyObject->marker = 'v1-resolution';

        $classVersion = $this->createMock(ClassVersionInterface::class);
        $classVersion->expects($this->once())
            ->method('__invoke')
            ->with(stdClass::class, 'v1')
            ->willReturn($proxyObject);

        $domain = new class ($classVersion) extends DomainApp {
            public function __construct(private ClassVersionInterface $cv)
            {
            }

            protected function version(): string
            {
                return 'v1';
            }

            protected function classVersion(): ClassVersionInterface
            {
                return $this->cv;
            }
        };

        $result = $this->invokeHandle($domain, stdClass::class);

        $this->assertSame($proxyObject, $result);
    }

    public function testHandleFallsBackToGeneratorBaseWhenClassVersionReturnsNull(): void
    {
        $classVersion = $this->createMock(ClassVersionInterface::class);
        $classVersion->method('__invoke')->willReturn(null);

        $domain = new class ($classVersion) extends DomainApp {
            public function __construct(private ClassVersionInterface $cv)
            {
            }

            protected function classVersion(): ClassVersionInterface
            {
                return $this->cv;
            }
        };

        $result = $this->invokeHandle($domain, stdClass::class);

        $this->assertInstanceOf(stdClass::class, $result);
    }

    // =========================================================================
    // Helper
    // =========================================================================

    private function invokeKernel(DomainApp $domain): DomainKernelInterface
    {
        $reflection = new \ReflectionMethod($domain, 'kernel');
        $reflection->setAccessible(true);

        return $reflection->invoke($domain);
    }

    private function invokeHandle(DomainApp $domain, string $className, mixed ...$parameters): mixed
    {
        $reflection = new \ReflectionMethod($domain, 'handle');
        $reflection->setAccessible(true);

        return $reflection->invoke($domain, $className, ...$parameters);
    }
}
