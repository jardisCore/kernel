<?php

declare(strict_types=1);

namespace FakeDomain\Bc\Agg\v1\Command\Handler;

use FakeDomain\Bc\Agg\Platform\Command\Handler\VersionedHandler as PlatformVersionedHandler;

/**
 * v1 dev-override at aggregate-root level. Reader picks this up when version
 * 'v1' is active before falling through to versioned Platform overrides or
 * the Platform-baseline.
 */
class VersionedHandler extends PlatformVersionedHandler
{
    public function origin(): string
    {
        return 'extensions-v1';
    }
}
