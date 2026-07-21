<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Tests\Unit\Bootstrap\Handler;

use Closure;
use JardisCore\Kernel\Bootstrap\Handler\BuildMailerFromEnv;
use JardisSupport\Contract\Mailer\MailerInterface;
use PHPUnit\Framework\TestCase;
use ValueError;

/**
 * Tests for BuildMailerFromEnv — ported 1:1 from foundation's MailerHandler.
 *
 * Building the mailer performs no network I/O (SMTP connects lazily on
 * send()), so these tests need no real mail server.
 */
final class BuildMailerFromEnvTest extends TestCase
{
    public function testNoHostReturnsNull(): void
    {
        $mailer = (new BuildMailerFromEnv())($this->envFrom([]));

        self::assertNull($mailer);
    }

    public function testBuildsMailerWhenHostIsConfigured(): void
    {
        $mailer = (new BuildMailerFromEnv())($this->envFrom(['mail_host' => 'smtp.example.com']));

        self::assertInstanceOf(MailerInterface::class, $mailer);
    }

    public function testBuildsMailerWithFullConfig(): void
    {
        $mailer = (new BuildMailerFromEnv())($this->envFrom([
            'mail_host' => 'smtp.example.com',
            'mail_port' => '465',
            'mail_encryption' => 'ssl',
            'mail_username' => 'user@example.com',
            'mail_password' => 'secret',
            'mail_timeout' => '10',
            'mail_from_address' => 'noreply@example.com',
            'mail_from_name' => 'App',
        ]));

        self::assertInstanceOf(MailerInterface::class, $mailer);
    }

    public function testInvalidEncryptionValuePropagatesAsValueError(): void
    {
        $this->expectException(ValueError::class);

        (new BuildMailerFromEnv())($this->envFrom([
            'mail_host' => 'smtp.example.com',
            'mail_encryption' => 'not-a-valid-encryption',
        ]));
    }

    /**
     * @param array<string, mixed> $data
     * @return Closure(string): mixed
     */
    private function envFrom(array $data): Closure
    {
        return static fn (string $key): mixed => $data[strtolower($key)] ?? null;
    }
}
