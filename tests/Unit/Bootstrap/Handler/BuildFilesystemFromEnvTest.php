<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Tests\Unit\Bootstrap\Handler;

use JardisCore\Kernel\Bootstrap\Handler\BuildFilesystemFromEnv;
use JardisSupport\Contract\Filesystem\FilesystemServiceInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tests for BuildFilesystemFromEnv — ported 1:1 from foundation's FilesystemHandler.
 *
 * The `!class_exists(FilesystemService::class)` branch (adapter not installed
 * -> null) cannot be exercised here: jardisadapter/filesystem is a require-dev
 * dependency of this very test suite, so the class always exists in this
 * environment. Documented gap rather than a fake — uninstalling a composer
 * package mid-suite is not a meaningful test.
 */
final class BuildFilesystemFromEnvTest extends TestCase
{
    public function testReturnsFilesystemServiceInstance(): void
    {
        $filesystem = (new BuildFilesystemFromEnv())();

        self::assertInstanceOf(FilesystemServiceInterface::class, $filesystem);
    }
}
