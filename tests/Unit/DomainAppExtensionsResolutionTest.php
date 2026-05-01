<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Tests\Unit;

use FakeDomain\Bc\Agg\Command\Handler\BaselineHandler;
use FakeDomain\Bc\Agg\Command\Handler\GeneratorOnlyHandler;
use FakeDomain\Bc\Agg\Command\Handler\VersionedHandler;
use FakeDomain\FakeDomainApp;
use FakeDomain\FakeDomainAppV1;
use JardisCore\Kernel\DomainApp;
use JardisSupport\ClassVersion\ClassVersion;
use JardisSupport\ClassVersion\Reader\LoadClassFromExtensions;
use JardisSupport\ClassVersion\Support\ClassResolutionCache;
use JardisSupport\Contract\ClassVersion\ClassVersionInterface;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * End-to-end resolution via DomainApp::handle() against Extensions-style
 * fixtures. Verifies that the LoadClassFromExtensions reader + ClassResolutionCache
 * wired up in DomainApp::classVersion() pick up baseline + versioned overrides
 * and fall back to the generator base when no override exists.
 */
class DomainAppExtensionsResolutionTest extends TestCase
{
    protected function setUp(): void
    {
        $reflection = new \ReflectionClass(DomainApp::class);
        $prop = $reflection->getProperty('sharedRegistry');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    public function testHandleResolvesBaselineExtensionOverride(): void
    {
        $app = new FakeDomainApp();

        /** @var BaselineHandler $resolved */
        $resolved = $this->invokeHandle($app, BaselineHandler::class);

        $this->assertInstanceOf(BaselineHandler::class, $resolved);
        $this->assertSame('extensions-baseline', $resolved->origin());
        $this->assertSame(
            'FakeDomain\\Bc\\Agg\\Extensions\\Command\\Handler\\BaselineHandler',
            $resolved::class,
        );
    }

    public function testHandleResolvesVersionedExtensionOverrideWhenVersionIsActive(): void
    {
        $app = new FakeDomainAppV1();

        /** @var VersionedHandler $resolved */
        $resolved = $this->invokeHandle($app, VersionedHandler::class);

        $this->assertInstanceOf(VersionedHandler::class, $resolved);
        $this->assertSame('extensions-v1', $resolved->origin());
        $this->assertSame(
            'FakeDomain\\Bc\\Agg\\Extensions\\v1\\Command\\Handler\\VersionedHandler',
            $resolved::class,
        );
    }

    public function testHandleFallsBackToGeneratorBaseWhenNoExtensionExists(): void
    {
        $app = new FakeDomainApp();

        /** @var GeneratorOnlyHandler $resolved */
        $resolved = $this->invokeHandle($app, GeneratorOnlyHandler::class);

        $this->assertInstanceOf(GeneratorOnlyHandler::class, $resolved);
        $this->assertSame('generator', $resolved->origin());
        $this->assertSame(GeneratorOnlyHandler::class, $resolved::class);
    }

    public function testHandleFallsBackFromMissingVersionedOverrideToBaselineExtension(): void
    {
        // FakeDomainAppV1 activates version 'v1'. BaselineHandler has *no* v1
        // override, only a versionless baseline — the reader must walk the
        // chain (v1 → baseline) and resolve the baseline.
        $app = new FakeDomainAppV1();

        /** @var BaselineHandler $resolved */
        $resolved = $this->invokeHandle($app, BaselineHandler::class);

        $this->assertSame(
            'FakeDomain\\Bc\\Agg\\Extensions\\Command\\Handler\\BaselineHandler',
            $resolved::class,
        );
    }

    public function testHandleFallsBackFromMissingVersionedAndBaselineToGeneratorBase(): void
    {
        // FakeDomainAppV1 activates version 'v1'. GeneratorOnlyHandler has
        // neither v1 nor baseline override — must fall through to the
        // generator base class.
        $app = new FakeDomainAppV1();

        /** @var GeneratorOnlyHandler $resolved */
        $resolved = $this->invokeHandle($app, GeneratorOnlyHandler::class);

        $this->assertSame(GeneratorOnlyHandler::class, $resolved::class);
    }

    public function testClassVersionIsBuiltWithLoadClassFromExtensionsAndCache(): void
    {
        $app = new FakeDomainApp();

        $method = new ReflectionMethod($app, 'classVersion');
        $method->setAccessible(true);

        /** @var ClassVersionInterface $classVersion */
        $classVersion = $method->invoke($app);

        $this->assertInstanceOf(ClassVersion::class, $classVersion);

        $finderProp = new ReflectionProperty(ClassVersion::class, 'classFinder');
        $finderProp->setAccessible(true);
        $this->assertInstanceOf(LoadClassFromExtensions::class, $finderProp->getValue($classVersion));

        $cacheProp = new ReflectionProperty(ClassVersion::class, 'cache');
        $cacheProp->setAccessible(true);
        $this->assertInstanceOf(ClassResolutionCache::class, $cacheProp->getValue($classVersion));
    }

    public function testCacheMemoizesResolutionAcrossRepeatedHandleCalls(): void
    {
        $app = new FakeDomainApp();

        $first = $this->invokeHandle($app, BaselineHandler::class);
        $second = $this->invokeHandle($app, BaselineHandler::class);

        $this->assertSame($first::class, $second::class);

        // Grab the ClassVersion from the Factory container and assert its
        // ClassResolutionCache recorded exactly the classes we asked for.
        $kernelMethod = new ReflectionMethod($app, 'kernel');
        $kernelMethod->setAccessible(true);
        $kernel = $kernelMethod->invoke($app);

        /** @var ClassVersion $classVersion */
        $classVersion = $kernel->container()->get(ClassVersionInterface::class);
        $cacheProp = new ReflectionProperty(ClassVersion::class, 'cache');
        $cacheProp->setAccessible(true);
        /** @var ClassResolutionCache $cache */
        $cache = $cacheProp->getValue($classVersion);

        $hitsProp = new ReflectionProperty(ClassResolutionCache::class, 'hits');
        $hitsProp->setAccessible(true);
        $hits = $hitsProp->getValue($cache);

        $this->assertIsArray($hits);
        $this->assertArrayHasKey(BaselineHandler::class . '|', $hits);
    }

    private function invokeHandle(DomainApp $app, string $className, mixed ...$parameters): mixed
    {
        $reflection = new ReflectionMethod($app, 'handle');
        $reflection->setAccessible(true);

        return $reflection->invoke($app, $className, ...$parameters);
    }
}
