<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Bootstrap\Handler;

/**
 * Reads one key out of the normalized ENV array the way every Handler closure
 * expects it: case-insensitive, with an explicitly empty value (`KEY=`) read
 * as "not configured" rather than as the empty string.
 *
 * One place instead of every Handler doing its own `''` check (R1.3 rule 1).
 * There is no process-environment fallback here (R3, G16) — the packer is
 * file-/string-pure, and precedence over the process environment is already
 * settled inside jardissupport/dotenv (>= 1.4.0), which resolves the ambient
 * value into the array this unit reads.
 *
 * Deliberately NOT delegating to {@see IsEnvValueUnset}: that unit also reads
 * the cast `bool(false)` as "absent", which is correct for string-shaped keys
 * that can never legitimately BE false (a hostname, a handler list) but wrong
 * here. This lookup serves every key, boolean ones included — swallowing
 * `false` would turn `HTTP_VERIFY_SSL=false` and `DB_POOL_STICKY_WRITER=false`
 * into "unset" and silently reinstate the adapter default
 * ({@see NormalizeEnvBool}, which needs the real `false` to arrive).
 */
final class ReadEnvValue
{
    /**
     * @param array<string, mixed> $env
     */
    public function __invoke(array $env, string $key): mixed
    {
        $value = $env[strtolower($key)] ?? null;

        return $value === '' ? null : $value;
    }
}
