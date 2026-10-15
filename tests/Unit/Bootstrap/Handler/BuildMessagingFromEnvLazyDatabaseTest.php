<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Tests\Unit\Bootstrap\Handler;

use Closure;
use JardisCore\Kernel\Bootstrap\Handler\BuildMessagingFromEnv;
use JardisCore\Kernel\Exception\InvalidEnvConfigurationException;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Bescheid Rolf 2026-10-15: alle Dienste lazy — the `database` transport takes
 * the writer PDO as a provider and asks for it only when the publisher or the
 * consumer is first built.
 */
final class BuildMessagingFromEnvLazyDatabaseTest extends TestCase
{
    public function testProviderIsNotCalledWhileBuildingAndCalledOnceOnFirstPublish(): void
    {
        $calls = 0;
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec(
            'CREATE TABLE domain_events (id INTEGER PRIMARY KEY AUTOINCREMENT, topic VARCHAR(255) NOT NULL, '
            . 'payload TEXT NOT NULL, created_at TEXT NOT NULL, processed_at TEXT NULL DEFAULT NULL, '
            . 'attempts INTEGER NOT NULL DEFAULT 0, last_error TEXT NULL DEFAULT NULL)'
        );

        $messaging = (new BuildMessagingFromEnv())(
            $this->envFrom(['messaging_transport' => 'database']),
            function () use (&$calls, $pdo): PDO {
                $calls++;

                return $pdo;
            },
        );

        self::assertNotNull($messaging);
        self::assertSame(0, $calls);
        self::assertTrue($messaging->publish('topic', 'one'));
        self::assertTrue($messaging->publish('topic', 'two'));
        self::assertSame(1, $calls);
    }

    public function testWithoutAnyProviderTheMissingDatabaseStillThrowsAtBuild(): void
    {
        $this->expectException(InvalidEnvConfigurationException::class);
        $this->expectExceptionMessage('requires a configured database');

        (new BuildMessagingFromEnv())($this->envFrom(['messaging_transport' => 'database']), null);
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
