<?php

declare(strict_types=1);

namespace FakeDomain\Bc\Agg\Platform\Command\Handler;

/**
 * Generator-base Handler under Platform/. Has a baseline dev-override at
 * FakeDomain\Bc\Agg\Command\Handler\BaselineHandler (aggregate-root level).
 */
class BaselineHandler
{
    public function origin(): string
    {
        return 'generator';
    }
}
