<?php

declare(strict_types=1);

namespace FakeDomain\Bc\Agg\Extensions\Command\Handler;

use FakeDomain\Bc\Agg\Command\Handler\BaselineHandler as GeneratedBaselineHandler;

/**
 * Versionless baseline Extensions override for
 * FakeDomain\Bc\Agg\Command\Handler\BaselineHandler.
 */
class BaselineHandler extends GeneratedBaselineHandler
{
    public function origin(): string
    {
        return 'extensions-baseline';
    }
}
