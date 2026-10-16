<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Tests\Unit\Bootstrap\Handler;

use Closure;
use JardisAdapter\Logger\Handler\LogRedis;
use JardisAdapter\Logger\Logger;
use JardisCore\Kernel\Bootstrap\Handler\BuildLoggerFromEnv;
use JardisCore\Kernel\Exception\InvalidEnvConfigurationException;
use JardisCore\Kernel\Tests\Support\CapturesErrorLog;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for BuildLoggerFromEnv — ported 1:1 from foundation's LoggerHandler.
 */
final class BuildLoggerFromEnvTest extends TestCase
{
    use CapturesErrorLog;

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

    public function testRedisProviderIsNotCalledWhenNoRedisHandlerIsListed(): void
    {
        // Bescheid Rolf 2026-10-15: alle Dienste lazy.
        $calls = 0;

        $logger = (new BuildLoggerFromEnv())(
            $this->envFrom(['log_handlers' => 'null']),
            function () use (&$calls): \Redis {
                $calls++;

                throw new \RuntimeException('must not be reached');
            },
        );

        self::assertInstanceOf(LoggerInterface::class, $logger);
        self::assertSame(0, $calls);
    }

    public function testRedisProviderIsNotCalledWhileBuildingEvenWhenARedisHandlerIsListed(): void
    {
        // Bescheid Rolf 2026-10-15: alle Dienste lazy — jardisadapter/logger
        // >= 1.1 takes the provider and calls it at the first log write.
        $calls = 0;

        $logger = (new BuildLoggerFromEnv())(
            $this->envFrom(['log_handlers' => 'redis']),
            function () use (&$calls): \Redis {
                $calls++;

                throw new InvalidEnvConfigurationException('Redis connection failed');
            },
        );

        self::assertInstanceOf(Logger::class, $logger);
        self::assertSame(0, $calls);
    }

    public function testRedisProviderFailureAtFirstLogDoesNotBreakTheCallerAndIsNotSwallowedByTheHandler(): void
    {
        $calls = 0;
        $logger = (new BuildLoggerFromEnv())(
            $this->envFrom(['log_handlers' => 'redis']),
            function () use (&$calls): \Redis {
                $calls++;

                throw new InvalidEnvConfigurationException('Redis connection failed');
            },
        );
        self::assertInstanceOf(Logger::class, $logger);

        // Logger::log() routes a handler failure to the error handler the
        // builder set — logging never throws into the application.
        $reported = $this->captureErrorLog(static fn () => $logger->error('first write'));
        self::assertStringContainsString('Redis connection failed', $reported);
        self::assertSame(1, $calls);

        // The handler itself does not swallow the provider's exception.
        $handlers = $logger->getHandlersByClass(LogRedis::class);
        self::assertCount(1, $handlers);
        $this->expectException(InvalidEnvConfigurationException::class);

        reset($handlers)->getRedis();
    }

    public function testKernelProviderFailureAtFirstLogIsReportedToErrorLogWithoutThrowing(): void
    {
        // O20: a configured Redis that cannot be reached must not stay invisible.
        $logger = (new BuildLoggerFromEnv())(
            $this->envFrom(['log_handlers' => 'redis']),
            static function (): \Redis {
                throw new InvalidEnvConfigurationException('Redis connection to host "cache.invalid" failed: refused');
            },
        );
        self::assertInstanceOf(Logger::class, $logger);
        $handlerIds = array_keys($logger->getHandlersByClass(LogRedis::class));

        $output = $this->captureErrorLog(static fn () => $logger->error('one line'));

        self::assertStringContainsString(
            sprintf(
                '[BuildLoggerFromEnv] log handler "%s" failed: Redis connection to host "cache.invalid" failed: refused',
                $handlerIds[0],
            ),
            $output,
        );
    }

    public function testOtherHandlerFailuresStaySilent(): void
    {
        // Logger semantics: only the kernel's own configuration failure is reported.
        $logger = (new BuildLoggerFromEnv())(
            $this->envFrom(['log_handlers' => 'redis']),
            static function (): \Redis {
                throw new \RuntimeException('some other failure');
            },
        );
        self::assertInstanceOf(Logger::class, $logger);

        $output = $this->captureErrorLog(static fn () => $logger->error('one line'));

        self::assertSame('', $output);
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
