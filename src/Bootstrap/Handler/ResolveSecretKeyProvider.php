<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Bootstrap\Handler;

use Closure;
use JardisSupport\Secret\Handler\SecretHandler;
use JardisSupport\Secret\KeyProvider\EnvKeyProvider;
use JardisSupport\Secret\KeyProvider\FileKeyProvider;

/**
 * Picks the encryption key source for a project root — the two-step key chain
 * (O9), in this order:
 *
 * 1. `APP_SECRET_KEY` in the PROCESS environment (set = non-whitespace value)
 *    -> {@see EnvKeyProvider}. The master key belongs in the process
 *    environment, never in a `.env` file: a file entry would be read by the
 *    very load it is supposed to unlock (henne-ei) and would sit in the `$env`
 *    array in plaintext.
 * 2. `<projectRoot>/support/secret.key` -> {@see FileKeyProvider}.
 * 3. Neither -> `null`, i.e. no secret resolution at all. An unresolvable
 *    `secret(...)` value is then caught by {@see AssertNoUnresolvedSecret}.
 *
 * The chain is independent of the load mode: a key file still applies when the
 * values themselves come from a string, because the key source and the value
 * source are two different questions.
 */
final class ResolveSecretKeyProvider
{
    /** ENV variable holding the encryption key; read from the process environment only. */
    public const KEY_ENV_NAME = 'APP_SECRET_KEY';

    /** Key file path, relative to the project root. */
    public const KEY_FILE_PATH = 'support/secret.key';

    private readonly Closure $readProcessEnv;

    public function __construct(?ReadProcessEnv $readProcessEnv = null)
    {
        $this->readProcessEnv = ($readProcessEnv ?? new ReadProcessEnv())->__invoke(...);
    }

    public function __invoke(string $projectRoot): EnvKeyProvider|FileKeyProvider|null
    {
        if (!class_exists(SecretHandler::class)) {
            // @codeCoverageIgnoreStart
            // jardissupport/secret is a require-dev dependency of this very
            // test suite, so this branch (package not installed) is structurally
            // unreachable here — documented gap, not a real path in this repo's QA.
            return null;
            // @codeCoverageIgnoreEnd
        }

        $envValue = ($this->readProcessEnv)(self::KEY_ENV_NAME);

        if ($envValue !== null && trim($envValue) !== '') {
            return new EnvKeyProvider(self::KEY_ENV_NAME);
        }

        $keyFile = $projectRoot . '/' . self::KEY_FILE_PATH;

        if (!is_file($keyFile)) {
            return null;
        }

        return new FileKeyProvider($keyFile);
    }
}
