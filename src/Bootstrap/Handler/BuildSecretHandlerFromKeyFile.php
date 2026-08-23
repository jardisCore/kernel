<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Bootstrap\Handler;

use JardisSupport\Secret\Handler\SecretHandler;
use JardisSupport\Secret\KeyProvider\FileKeyProvider;

/**
 * Builds the DotEnv secret handler for a project root's key file.
 *
 * Requires jardissupport/secret and an existing `<projectRoot>/support/secret.key`
 * (fixed convention). Missing package or missing key file both mean "no secret
 * resolution" (`null`), never an error — the same silent degradation as every
 * other optional adapter of the Bootstrap-Packer.
 */
final class BuildSecretHandlerFromKeyFile
{
    public function __invoke(string $projectRoot): ?SecretHandler
    {
        if (!class_exists(SecretHandler::class)) {
            // @codeCoverageIgnoreStart
            // jardissupport/secret is a require-dev dependency of this very
            // test suite, so this branch (package not installed) is structurally
            // unreachable here — documented gap, not a real path in this repo's QA.
            return null;
            // @codeCoverageIgnoreEnd
        }

        $keyFile = $projectRoot . '/support/secret.key';

        if (!is_file($keyFile)) {
            return null;
        }

        return new SecretHandler(new FileKeyProvider($keyFile));
    }
}
