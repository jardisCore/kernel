<?php

declare(strict_types=1);

namespace FakeDomain\Bc\Agg\Platform\Command\Handler;

/**
 * Generator-base Handler under Platform/. Has a v1 dev-override at
 * FakeDomain\Bc\Agg\v1\Command\Handler\VersionedHandler.
 */
class VersionedHandler
{
    public function origin(): string
    {
        return 'generator';
    }
}
