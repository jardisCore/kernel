<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Bootstrap\Handler;

use JardisAdapter\EventDispatcher\EventDispatcher;
use JardisAdapter\EventDispatcher\ListenerProvider;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Wraps the shared listener provider (`BuildEventListenerProviderFromEnv`) in a
 * PSR-14 dispatcher.
 *
 * Requires jardisadapter/eventdispatcher. No provider (package not installed,
 * or ENV disabled it upstream) → no dispatcher either, event routing stays
 * inactive (documented fallback, PRD AC4).
 *
 * Split out of `jardiscore/foundation`'s `Handler\EventDispatcherHandler`
 * (Kernel-Entkopplung P2, D3 — Dispatcher + Registry as a pair).
 */
final class BuildEventDispatcherFromProvider
{
    public function __invoke(?ListenerProvider $provider): ?EventDispatcherInterface
    {
        if ($provider === null || !class_exists(EventDispatcher::class)) {
            return null;
        }

        return new EventDispatcher($provider);
    }
}
