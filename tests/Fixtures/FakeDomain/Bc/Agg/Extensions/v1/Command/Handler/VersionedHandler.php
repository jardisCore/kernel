<?php

declare(strict_types=1);

namespace FakeDomain\Bc\Agg\Extensions\v1\Command\Handler;

use FakeDomain\Bc\Agg\Command\Handler\VersionedHandler as GeneratedVersionedHandler;

/**
 * v1 Extensions override for
 * FakeDomain\Bc\Agg\Command\Handler\VersionedHandler.
 */
class VersionedHandler extends GeneratedVersionedHandler
{
    public function origin(): string
    {
        return 'extensions-v1';
    }
}
