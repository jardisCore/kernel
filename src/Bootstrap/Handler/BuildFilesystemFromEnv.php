<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Bootstrap\Handler;

use JardisAdapter\Filesystem\FilesystemService;
use JardisSupport\Contract\Filesystem\FilesystemServiceInterface;

/**
 * Provides the FilesystemService factory.
 *
 * Requires jardisadapter/filesystem. No ENV needed — the service is a stateless factory.
 *
 * Ported 1:1 from `jardiscore/foundation` (`Handler\FilesystemHandler`,
 * Kernel-Entkopplung P2).
 */
final class BuildFilesystemFromEnv
{
    public function __invoke(): ?FilesystemServiceInterface
    {
        if (!class_exists(FilesystemService::class)) {
            // @codeCoverageIgnoreStart
            // jardisadapter/filesystem is a require-dev dependency of this very
            // test suite, so this branch (adapter not installed) is structurally
            // unreachable here — documented gap, not a real path in this repo's QA.
            return null;
            // @codeCoverageIgnoreEnd
        }

        return new FilesystemService();
    }
}
