<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Bootstrap\Handler;

use Closure;
use JardisAdapter\Mailer\Config\Encryption;
use JardisAdapter\Mailer\Config\SmtpConfig;
use JardisAdapter\Mailer\Mailer;
use JardisSupport\Contract\Mailer\MailerInterface;

/**
 * Builds a Mailer from ENV values.
 *
 * Requires jardisadapter/mailer. Configuration via MAIL_* environment variables.
 *
 * Ported 1:1 from `jardiscore/foundation` (`Handler\MailerHandler`,
 * Kernel-Entkopplung P2).
 */
final class BuildMailerFromEnv
{
    /** @param Closure(string): mixed $env */
    public function __invoke(Closure $env): ?MailerInterface
    {
        if (!class_exists(Mailer::class)) {
            // @codeCoverageIgnoreStart
            // jardisadapter/mailer is a require-dev dependency of this very test
            // suite, so this branch (adapter not installed) is structurally
            // unreachable here — documented gap, not a real path in this repo's QA.
            return null;
            // @codeCoverageIgnoreEnd
        }

        $host = $env('mail_host');
        if ($host === null) {
            return null;
        }

        return new Mailer(new SmtpConfig(
            host: (string) $host,
            port: (int) ($env('mail_port') ?? 587),
            encryption: Encryption::from((string) ($env('mail_encryption') ?? 'tls')),
            username: $env('mail_username') !== null ? (string) $env('mail_username') : null,
            password: $env('mail_password') !== null ? (string) $env('mail_password') : null,
            timeout: (int) ($env('mail_timeout') ?? 30),
            fromAddress: $env('mail_from_address') !== null ? (string) $env('mail_from_address') : null,
            fromName: $env('mail_from_name') !== null ? (string) $env('mail_from_name') : null,
        ));
    }
}
