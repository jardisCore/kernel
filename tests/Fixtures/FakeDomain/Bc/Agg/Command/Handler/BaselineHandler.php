<?php

declare(strict_types=1);

namespace FakeDomain\Bc\Agg\Command\Handler;

use FakeDomain\Bc\Agg\Platform\Command\Handler\BaselineHandler as PlatformBaselineHandler;

/**
 * Versionless dev-baseline override (Stub-Slot equivalent for handler classes).
 * Lives at the aggregate-root level; reader's segment='' lookup picks it up
 * before falling through to Platform/.
 */
class BaselineHandler extends PlatformBaselineHandler
{
    public function origin(): string
    {
        return 'extensions-baseline';
    }
}
