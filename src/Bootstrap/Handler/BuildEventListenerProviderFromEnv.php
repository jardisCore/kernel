<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Bootstrap\Handler;

use JardisAdapter\EventDispatcher\ListenerProvider;

/**
 * Builds the shared listener provider for the event dispatcher pair (D3).
 *
 * Requires jardisadapter/eventdispatcher. No ENV configuration needed — the
 * provider is a pure in-memory service. `ListenerProvider` implements both the
 * PSR-14 `ListenerProviderInterface` and the contract's
 * `EventListenerRegistryInterface` — the same instance backs
 * `DomainKernel::eventDispatcher()` (via `BuildEventDispatcherFromProvider`)
 * and `DomainKernel::eventListenerRegistry()`, so generated `{Agg}EventRouter`
 * scaffolds can register themselves without any Application wiring.
 *
 * Split out of `jardiscore/foundation`'s `Handler\EventDispatcherHandler`
 * (Kernel-Entkopplung P2, D3 — Dispatcher + Registry as a pair).
 */
final class BuildEventListenerProviderFromEnv
{
    public function __invoke(): ?ListenerProvider
    {
        if (!class_exists(ListenerProvider::class)) {
            // @codeCoverageIgnoreStart
            // jardisadapter/eventdispatcher is a require-dev dependency of this
            // very test suite, so this branch (adapter not installed) is
            // structurally unreachable here — documented gap, not a real path
            // in this repo's QA.
            return null;
            // @codeCoverageIgnoreEnd
        }

        return new ListenerProvider();
    }
}
