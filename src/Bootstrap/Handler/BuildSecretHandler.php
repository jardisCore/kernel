<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Bootstrap\Handler;

use JardisSupport\Secret\Handler\SecretHandler;
use JardisSupport\Secret\KeyProvider\EnvKeyProvider;
use JardisSupport\Secret\KeyProvider\FileKeyProvider;

/**
 * Wraps a resolved key provider ({@see ResolveSecretKeyProvider}) into the
 * DotEnv secret handler.
 *
 * No provider or a missing jardissupport/secret package both mean "no secret
 * resolution" (`null`), never an error here — the same silent degradation as
 * every other optional adapter of the Bootstrap-Packer. What it does NOT mean
 * is "pass a cipher on as a value": that case is caught by
 * {@see AssertNoUnresolvedSecret}.
 */
final class BuildSecretHandler
{
    public function __invoke(EnvKeyProvider|FileKeyProvider|null $keyProvider): ?SecretHandler
    {
        if ($keyProvider === null) {
            return null;
        }

        if (!class_exists(SecretHandler::class)) {
            // @codeCoverageIgnoreStart
            // Unreachable in this repo's QA: a non-null provider can only come
            // from ResolveSecretKeyProvider, which itself requires the package.
            return null;
            // @codeCoverageIgnoreEnd
        }

        return new SecretHandler($keyProvider);
    }
}
