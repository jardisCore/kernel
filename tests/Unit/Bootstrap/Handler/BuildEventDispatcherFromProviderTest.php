<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Tests\Unit\Bootstrap\Handler;

use JardisAdapter\EventDispatcher\ListenerProvider;
use JardisCore\Kernel\Bootstrap\Handler\BuildEventDispatcherFromProvider;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use stdClass;

/**
 * Tests for BuildEventDispatcherFromProvider — split out of foundation's
 * EventDispatcherHandler for the Dispatcher+Registry pairing (D3).
 */
final class BuildEventDispatcherFromProviderTest extends TestCase
{
    public function testNullProviderYieldsNullDispatcher(): void
    {
        $dispatcher = (new BuildEventDispatcherFromProvider())(null);

        self::assertNull($dispatcher);
    }

    public function testWrapsProviderAndDispatchesToRegisteredListeners(): void
    {
        $provider = new ListenerProvider();
        $received = null;
        $provider->listen(stdClass::class, static function (stdClass $event) use (&$received): void {
            $received = $event;
        });

        $dispatcher = (new BuildEventDispatcherFromProvider())($provider);

        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        $event = new stdClass();
        $dispatcher->dispatch($event);

        self::assertSame($event, $received);
    }
}
