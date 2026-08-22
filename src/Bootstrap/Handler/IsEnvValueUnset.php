<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Bootstrap\Handler;

/**
 * True when an ENV value must be treated as "not configured" for a
 * string-shaped presence check (a hostname, a handler list, ... — a field
 * that can never legitimately BE the boolean `false`).
 *
 * DotEnv's cast chain (`CastStringToBool`, via `filter_var(...,
 * FILTER_VALIDATE_BOOLEAN)`) treats an explicitly empty value (`KEY=`) as
 * the literal `false` — PHP's filter explicitly lists `""` among the
 * false-representations, so it is not passed through as `''` the way every
 * other cast stage leaves an unmatched string. Once cast, `KEY=` and
 * `KEY=false` are indistinguishable `bool(false)` values. Measured directly
 * against the real DotEnv cascade (not assumed) while building the
 * Integration suite for R1.3 rule 1 (G5: "fehlend = ... oder leerer Wert").
 *
 * For a field that is never legitimately boolean, both `null`, `''` and this
 * cast `false` mean the same thing: absent. Handlers with a genuinely
 * boolean key ({@see NormalizeEnvBool}) do not use this unit — there, `false`
 * is a real, valid value.
 */
final class IsEnvValueUnset
{
    public function __invoke(mixed $value): bool
    {
        return $value === null || $value === false || $value === '';
    }
}
