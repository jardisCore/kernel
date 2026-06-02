<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Tests\Unit;

use FakeDomain\Bc\Agg\Command\Handler\BaselineHandler as DevBaselineHandler;
use FakeDomain\Bc\Agg\Platform\Command\Handler\BaselineHandler as PlatformBaselineHandler;
use FakeDomain\Bc\Agg\Platform\Command\Handler\GeneratorOnlyHandler;
use FakeDomain\Bc\Agg\Platform\Command\Handler\VersionedHandler as PlatformVersionedHandler;
use FakeDomain\Bc\Agg\v1\Command\Handler\VersionedHandler as DevV1VersionedHandler;
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
 * End-to-end resolution via DomainApp::handle() against PD-style fixtures.
 *
 * Verifies that the LoadClassFromExtensions reader + ClassResolutionCache
 * wired up in DomainApp::classVersion() (segmentNames: ['', 'Platform'])
 * pick up dev-baseline + versioned dev-overrides at the aggregate-root level
 * and fall back to the Platform/-baseline when no override exists.
 *
 * Lookup form: handle() receives the logical (root-level) FQN of the class.
 * The actual generator-base class lives under Platform/. Reader walks
 * (v_n@'', v_n@'Platform', …, ''@root, 'Platform'@root).
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

    public function testHandleResolvesDevBaselineOverride(): void
    {
        $app = new FakeDomainApp();

        $resolved = $this->invokeHandle($app, DevBaselineHandler::class);

        $this->assertInstanceOf(DevBaselineHandler::class, $resolved);
        $this->assertSame('extensions-baseline', $resolved->origin());
        $this->assertSame(
            'FakeDomain\\Bc\\Agg\\Command\\Handler\\BaselineHandler',
            $resolved::class,
        );
    }

    public function testHandleResolvesVersionedDevOverrideWhenVersionIsActive(): void
    {
        $app = new FakeDomainAppV1();

        // Use the logical (root-level) FQN as input — reader walks the chain.
        $resolved = $this->invokeHandle(
            $app,
            'FakeDomain\\Bc\\Agg\\Command\\Handler\\VersionedHandler',
        );

        $this->assertInstanceOf(DevV1VersionedHandler::class, $resolved);
        $this->assertSame('extensions-v1', $resolved->origin());
        $this->assertSame(
            'FakeDomain\\Bc\\Agg\\v1\\Command\\Handler\\VersionedHandler',
            $resolved::class,
        );
    }

    public function testHandleFallsBackToPlatformBaseWhenNoOverrideExists(): void
    {
        $app = new FakeDomainApp();

        // GeneratorOnlyHandler has no dev-override at all — reader must walk
        // segment='' (miss), segment='Platform' (hit) and return the Platform
        // generator base.
        $resolved = $this->invokeHandle(
            $app,
            'FakeDomain\\Bc\\Agg\\Command\\Handler\\GeneratorOnlyHandler',
        );

        $this->assertInstanceOf(GeneratorOnlyHandler::class, $resolved);
        $this->assertSame('generator', $resolved->origin());
        $this->assertSame(GeneratorOnlyHandler::class, $resolved::class);
    }

    public function testHandleFallsBackFromMissingVersionedOverrideToBaselineDevOverride(): void
    {
        // FakeDomainAppV1 activates version 'v1'. BaselineHandler has *no*
        // v1 override — only a versionless dev-baseline at the aggregate
        // root. Reader walks (v1@'', v1@'Platform', ''@root, 'Platform'@root)
        // and lands on the dev-baseline.
        $app = new FakeDomainAppV1();

        $resolved = $this->invokeHandle($app, DevBaselineHandler::class);

        $this->assertSame(
            'FakeDomain\\Bc\\Agg\\Command\\Handler\\BaselineHandler',
            $resolved::class,
        );
    }

    public function testHandleFallsBackFromMissingVersionedAndDevBaselineToPlatformBase(): void
    {
        // FakeDomainAppV1 activates version 'v1'. GeneratorOnlyHandler has
        // neither v1 nor dev-baseline override — must fall through to the
        // Platform-baseline.
        $app = new FakeDomainAppV1();

        $resolved = $this->invokeHandle(
            $app,
            'FakeDomain\\Bc\\Agg\\Command\\Handler\\GeneratorOnlyHandler',
        );

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

        $first = $this->invokeHandle($app, DevBaselineHandler::class);
        $second = $this->invokeHandle($app, DevBaselineHandler::class);

        $this->assertSame($first::class, $second::class);

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
        $this->assertArrayHasKey(DevBaselineHandler::class . '|', $hits);
    }

    /** @suppress all */
    private function invokeHandle(DomainApp $app, string $className, mixed ...$parameters): mixed
    {
        $reflection = new ReflectionMethod($app, 'handle');
        $reflection->setAccessible(true);

        return $reflection->invoke($app, $className, ...$parameters);
    }

    /** Suppress unused-import warning — kept for documentation symmetry. */
    private function _keepImports(): void
    {
        $_ = PlatformBaselineHandler::class;
        $_ = PlatformVersionedHandler::class;
    }
}
