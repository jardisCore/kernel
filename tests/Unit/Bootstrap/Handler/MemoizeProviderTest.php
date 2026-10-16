<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Tests\Unit\Bootstrap\Handler;

use JardisCore\Kernel\Bootstrap\Handler\MemoizeProvider;
use PHPUnit\Framework\TestCase;

final class MemoizeProviderTest extends TestCase
{
    public function testBuildsOnFirstCallOnlyAndReturnsTheSameInstance(): void
    {
        $builds = 0;
        $provider = (new MemoizeProvider())(function () use (&$builds): \stdClass {
            $builds++;

            return new \stdClass();
        });

        self::assertSame(0, $builds);
        $first = $provider();
        self::assertSame($first, $provider());
        self::assertSame(1, $builds);
    }

    public function testAFailedBuildIsNotMemoizedAndTheNextCallBuildsAgain(): void
    {
        $builds = 0;
        $provider = (new MemoizeProvider())(function () use (&$builds): \stdClass {
            $builds++;
            if ($builds === 1) {
                throw new \RuntimeException('first attempt fails');
            }

            return new \stdClass();
        });

        try {
            $provider();
            self::fail('the first call must throw');
        } catch (\RuntimeException $e) {
            self::assertSame('first attempt fails', $e->getMessage());
        }

        $second = $provider();

        self::assertSame(2, $builds, 'the failure was not cached: the builder ran a second time');
        self::assertSame($second, $provider());
        self::assertSame(2, $builds);
    }
}
