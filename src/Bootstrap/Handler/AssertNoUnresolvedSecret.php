<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Bootstrap\Handler;

use JardisCore\Kernel\Exception\InvalidEnvConfigurationException;
use JardisSupport\Secret\Secret;

/**
 * Aborts the bootstrap when a `secret(...)` value survived the load unresolved
 * (O8).
 *
 * Without a key the DotEnv secret handler is absent, and an encrypted value
 * would reach the Handlers verbatim — a cipher silently used as a password, a
 * host, a token. That is a configuration error, not a degradation, so it is
 * loud: {@see InvalidEnvConfigurationException}, naming the KEY and the two
 * ways to supply a key — never the value, which would print the cipher into
 * whatever log catches the boot failure.
 *
 * Runs on the RAW load result (original key case, before
 * {@see NormalizeEnvKeys}) and only in the "no handler" case — with a handler
 * active, a still-wrapped value means the resolver itself failed and threw
 * long before this unit is reached.
 *
 * Only `is_string` values are examined: the marker format is a string format,
 * and the cast chain leaves an unmatched string untouched.
 */
final class AssertNoUnresolvedSecret
{
    /**
     * @param array<string, mixed> $rawEnv
     */
    public function __invoke(array $rawEnv): void
    {
        if (!class_exists(Secret::class)) {
            // @codeCoverageIgnoreStart
            // jardissupport/secret owns the marker format; without the package
            // there is nothing to ask, and duplicating its regex here would be
            // a second source of truth (G1b, rejected). Same documented gap as
            // every other optional-package branch in this packer.
            return;
            // @codeCoverageIgnoreEnd
        }

        foreach ($rawEnv as $key => $value) {
            if (!is_string($value) || !Secret::matches($value)) {
                continue;
            }

            throw new InvalidEnvConfigurationException(sprintf(
                'ENV key "%s" holds an unresolved secret(...) value: no encryption key was found. '
                . 'Set %s in the process environment or provide <projectRoot>/%s.',
                $key,
                ResolveSecretKeyProvider::KEY_ENV_NAME,
                ResolveSecretKeyProvider::KEY_FILE_PATH,
            ));
        }
    }
}
