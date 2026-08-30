<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Tests\Support;

/**
 * Saves and restores the three process-environment keys the Bootstrap-Packer
 * and jardissupport/dotenv read behind the test's back.
 *
 * Since dotenv 1.4.0 the process environment WINS over any file or string
 * value, so an ambient value leaks straight into the assertion:
 *
 * - `APP_ENV` — the phpcli image exports `APP_ENV=dev`, which overrides the
 *   fixture's own `APP_ENV=test` and therefore silently selects a different
 *   `.env.{APP_ENV}` overlay than the test intends.
 * - `APP_SECRET_KEY` — the first step of the kernel's key chain; a leftover
 *   from a previous test would decrypt (or fail to decrypt) values in a test
 *   that meant to run without it.
 * - `JARDIS_DOTENV_VARS` — dotenv's own marker for keys it published itself;
 *   a leftover marker makes a genuinely ambient key look like a file value.
 *
 * `putenv()`, `$_ENV` and `$_SERVER` are all covered: dotenv reads through
 * `getenv()`, the superglobals feed `resolveAppEnvFromResult()`.
 */
trait EnvIsolation
{
    /** @var array<string> */
    private const ISOLATED_ENV_KEYS = ['APP_ENV', 'APP_SECRET_KEY', 'JARDIS_DOTENV_VARS'];

    /** @var array<string, array{env: string|false, superEnv: mixed, server: mixed, hadEnv: bool, hadServer: bool}> */
    private array $savedProcessEnv = [];

    private function saveProcessEnv(): void
    {
        $this->savedProcessEnv = [];

        foreach (self::ISOLATED_ENV_KEYS as $key) {
            $this->rememberProcessEnv($key);
            $this->unsetProcessEnv($key);
        }
    }

    /**
     * Records a key's current process-environment state once, so
     * {@see restoreProcessEnv()} can put it back exactly as it was — including
     * "was not set at all".
     */
    private function rememberProcessEnv(string $key): void
    {
        if (array_key_exists($key, $this->savedProcessEnv)) {
            return;
        }

        $this->savedProcessEnv[$key] = [
            'env' => getenv($key),
            'superEnv' => $_ENV[$key] ?? null,
            'server' => $_SERVER[$key] ?? null,
            'hadEnv' => array_key_exists($key, $_ENV),
            'hadServer' => array_key_exists($key, $_SERVER),
        ];
    }

    private function restoreProcessEnv(): void
    {
        foreach ($this->savedProcessEnv as $key => $saved) {
            $this->unsetProcessEnv($key);

            if (is_string($saved['env'])) {
                putenv($key . '=' . $saved['env']);
            }
            if ($saved['hadEnv']) {
                $_ENV[$key] = $saved['superEnv'];
            }
            if ($saved['hadServer']) {
                $_SERVER[$key] = $saved['server'];
            }
        }

        $this->savedProcessEnv = [];
    }

    private function setProcessEnv(string $key, string $value): void
    {
        $this->rememberProcessEnv($key);
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    private function unsetProcessEnv(string $key): void
    {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }
}
