<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Tests\Unit\Bootstrap;

use JardisAdapter\Cache\Adapter\CacheMemory;
use JardisCore\Kernel\Bootstrap\LazyCacheLayer;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;

final class LazyCacheLayerTest extends TestCase
{
    public function testNothingIsBuiltUntilTheLayerIsUsed(): void
    {
        $builds = 0;
        $layer = new LazyCacheLayer(function () use (&$builds): CacheInterface {
            $builds++;

            return new CacheMemory();
        });

        self::assertInstanceOf(CacheInterface::class, $layer);
        self::assertSame(0, $builds);
    }

    public function testDelegatesEveryOperationAndBuildsOnce(): void
    {
        $builds = 0;
        $layer = new LazyCacheLayer(function () use (&$builds): CacheInterface {
            $builds++;

            return new CacheMemory();
        });

        self::assertTrue($layer->set('a', 1));
        self::assertTrue($layer->has('a'));
        self::assertSame(1, $layer->get('a'));
        self::assertTrue($layer->setMultiple(['b' => 2, 'c' => 3]));
        self::assertSame(['b' => 2, 'c' => 3], iterator_to_array($layer->getMultiple(['b', 'c'])));
        self::assertTrue($layer->deleteMultiple(['b']));
        self::assertFalse($layer->has('b'));
        self::assertTrue($layer->delete('c'));
        self::assertTrue($layer->clear());
        self::assertFalse($layer->has('a'));
        self::assertSame(1, $builds);
    }

    public function testBuildFailureSurfacesAtFirstUse(): void
    {
        $layer = new LazyCacheLayer(static fn (): CacheInterface => throw new \RuntimeException('unreachable'));

        $this->expectException(\RuntimeException::class);

        $layer->get('k');
    }
}
