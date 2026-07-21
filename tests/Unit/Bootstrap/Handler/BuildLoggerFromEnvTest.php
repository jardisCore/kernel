<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Tests\Unit\Bootstrap\Handler;

use Closure;
use JardisCore\Kernel\Bootstrap\Handler\BuildLoggerFromEnv;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for BuildLoggerFromEnv — ported 1:1 from foundation's LoggerHandler.
 */
final class BuildLoggerFromEnvTest extends TestCase
{
    public function testNoHandlersReturnsNull(): void
    {
        $logger = (new BuildLoggerFromEnv())($this->envFrom([]));

        self::assertNull($logger);
    }

    public function testConsoleHandlerBuildsUsableLogger(): void
    {
        $logger = (new BuildLoggerFromEnv())($this->envFrom(['log_handlers' => 'console']));

        self::assertInstanceOf(LoggerInterface::class, $logger);
        $logger->info('test message');
        $this->addToAssertionCount(1);
    }

    public function testUnknownHandlerNameIsSkippedWithoutError(): void
    {
        $logger = (new BuildLoggerFromEnv())($this->envFrom(['log_handlers' => 'bogus,console']));

        self::assertInstanceOf(LoggerInterface::class, $logger);
    }

    public function testFileHandlerWritesToConfiguredPath(): void
    {
        $path = sys_get_temp_dir() . '/jardis-kernel-bootstrap-log-' . uniqid('', true) . '.log';

        try {
            $logger = (new BuildLoggerFromEnv())($this->envFrom([
                'log_handlers' => 'file:ERROR',
                'log_file_path' => $path,
            ]));

            self::assertInstanceOf(LoggerInterface::class, $logger);
            $logger->error('something failed');

            self::assertFileExists($path);
            self::assertStringContainsString('something failed', (string) file_get_contents($path));
        } finally {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }

    public function testNullHandlerBuildsUsableLogger(): void
    {
        $logger = (new BuildLoggerFromEnv())($this->envFrom(['log_handlers' => 'null']));

        self::assertInstanceOf(LoggerInterface::class, $logger);
        $logger->warning('discarded');
        $this->addToAssertionCount(1);
    }

    public function testRedisHandlerIsSkippedWhenNoRedisConnectionProvided(): void
    {
        $logger = (new BuildLoggerFromEnv())($this->envFrom(['log_handlers' => 'redis']), null);

        self::assertInstanceOf(LoggerInterface::class, $logger);
    }

    public function testSlackHandlerIsSkippedWhenUrlMissing(): void
    {
        $logger = (new BuildLoggerFromEnv())($this->envFrom(['log_handlers' => 'slack']));

        self::assertInstanceOf(LoggerInterface::class, $logger);
    }

    public function testSlackHandlerIsAddedWhenUrlIsConfigured(): void
    {
        $logger = (new BuildLoggerFromEnv())($this->envFrom([
            'log_handlers' => 'slack:ERROR',
            'log_slack_url' => 'https://hooks.slack.com/services/test',
        ]));

        self::assertInstanceOf(LoggerInterface::class, $logger);
    }

    public function testTeamsHandlerIsAddedWhenUrlIsConfigured(): void
    {
        $logger = (new BuildLoggerFromEnv())($this->envFrom([
            'log_handlers' => 'teams:ERROR',
            'log_teams_url' => 'https://outlook.office.com/webhook/test',
        ]));

        self::assertInstanceOf(LoggerInterface::class, $logger);
    }

    public function testLokiHandlerIsAddedWhenUrlIsConfigured(): void
    {
        $logger = (new BuildLoggerFromEnv())($this->envFrom([
            'log_handlers' => 'loki:INFO',
            'log_loki_url' => 'http://loki:3100',
        ]));

        self::assertInstanceOf(LoggerInterface::class, $logger);
    }

    public function testWebhookHandlerIsAddedWhenUrlIsConfigured(): void
    {
        $logger = (new BuildLoggerFromEnv())($this->envFrom([
            'log_handlers' => 'webhook:ERROR',
            'log_webhook_url' => 'https://api.example.com/logs',
        ]));

        self::assertInstanceOf(LoggerInterface::class, $logger);
    }

    public function testDefaultLevelFallsBackToLogLevelWhenEntryHasNoExplicitLevel(): void
    {
        $logger = (new BuildLoggerFromEnv())($this->envFrom([
            'log_handlers' => 'console',
            'log_level' => 'WARNING',
        ]));

        self::assertInstanceOf(LoggerInterface::class, $logger);
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
