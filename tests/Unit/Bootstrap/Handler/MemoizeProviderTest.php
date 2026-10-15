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
}
