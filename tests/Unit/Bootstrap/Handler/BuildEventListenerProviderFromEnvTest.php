<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Tests\Unit\Bootstrap\Handler;

use JardisAdapter\EventDispatcher\ListenerProvider;
use JardisCore\Kernel\Bootstrap\Handler\BuildEventListenerProviderFromEnv;
use JardisSupport\Contract\EventListener\EventListenerRegistryInterface;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\ListenerProviderInterface;

/**
 * Tests for BuildEventListenerProviderFromEnv — split out of foundation's
 * EventDispatcherHandler for the Dispatcher+Registry pairing (D3).
 *
 * The `!class_exists(ListenerProvider::class)` branch (adapter not installed
 * -> null) cannot be exercised here: jardisadapter/eventdispatcher is a
 * require-dev dependency of this very test suite. Documented gap.
 */
final class BuildEventListenerProviderFromEnvTest extends TestCase
{
    public function testReturnsListenerProviderImplementingBothInterfaces(): void
    {
        $provider = (new BuildEventListenerProviderFromEnv())();

        self::assertInstanceOf(ListenerProvider::class, $provider);
        self::assertInstanceOf(ListenerProviderInterface::class, $provider);
        self::assertInstanceOf(EventListenerRegistryInterface::class, $provider);
    }

    public function testReturnsAFreshInstanceOnEachCall(): void
    {
        $handler = new BuildEventListenerProviderFromEnv();

        self::assertNotSame($handler(), $handler());
    }
}
