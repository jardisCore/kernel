<?php

declare(strict_types=1);

namespace FakeDomain\Bc\Agg\Command\Handler;

/**
 * Generator-base Handler. Has a versioned Extensions override in
 * FakeDomain\Bc\Agg\Extensions\v1\Command\Handler\VersionedHandler.
 */
class VersionedHandler
{
    public function origin(): string
    {
        return 'generator';
    }
}
