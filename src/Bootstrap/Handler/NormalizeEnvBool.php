<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Bootstrap\Handler;

use JardisCore\Kernel\Exception\InvalidEnvConfigurationException;

/**
 * Normalizes an already-cast ENV value into a strict bool.
 *
 * The DotEnv cast chain hands handlers a `bool` for literal `true`/`false`
 * and an `int` for `1`/`0` (never a raw string for those) — a Roh-String
 * comparison like `$value === 'true'` silently misreads both, which is
 * exactly the bug class this unit closes (R1, G4/G5). One shared unit
 * instead of a private copy per handler.
 *
 * Missing (`null`/`''`) stays the caller's null-degradation to apply a
 * default; anything else that cannot be read as a boolean is loud, not
 * silent (G5 rule 2).
 *
 * Known limitation (measured, not assumed): DotEnv's own cast chain reads an
 * explicitly empty value (`KEY=`) as the literal `bool(false)` — PHP's
 * `filter_var(..., FILTER_VALIDATE_BOOLEAN)` lists `""` among the
 * false-representations, so this unit sees `false`, never `''`, for that
 * case. For a boolean-typed key there is no sound way to tell `KEY=` apart
 * from `KEY=false` once cast — both legitimately mean "false". Unlike
 * {@see IsEnvValueUnset} (safe for string-shaped keys, which are never
 * legitimately boolean), this cannot be resolved at the kernel layer; a
 * genuine fix belongs in `jardissupport/dotenv`'s cast chain.
 */
final class NormalizeEnvBool
{
    public function __invoke(mixed $value, string $key): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value === 1;
        }

        if (is_string($value)) {
            $result = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($result !== null) {
                return $result;
            }
        }

        throw new InvalidEnvConfigurationException(sprintf(
            'Invalid boolean value for "%s": %s',
            $key,
            is_scalar($value) ? (string) $value : get_debug_type($value),
        ));
    }
}
