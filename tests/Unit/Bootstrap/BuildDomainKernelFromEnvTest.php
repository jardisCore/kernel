<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Tests\Unit\Bootstrap;

use JardisCore\Kernel\Bootstrap\BuildDomainKernelFromEnv;
use JardisSupport\Contract\EventListener\EventListenerRegistryInterface;
use JardisSupport\Contract\Filesystem\FilesystemServiceInterface;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Integration tests for BuildDomainKernelFromEnv — the Bootstrap-Packer (D4).
 *
 * Uses a config-cascade fixture (tests/Fixtures/Bootstrap/FullConfig/) that
 * exercises DotEnv's two-stage cascade (load()-included files + an
 * APP_ENV-specific overlay) — no Docker/real infrastructure required
 * (PRD AC3, Plan P2 AK): DB via SQLite in-memory, no Redis/Mailer configured.
 */
final class BuildDomainKernelFromEnvTest extends TestCase
{
    private function fixturePath(string $name = 'FullConfig'): string
    {
        return __DIR__ . '/../../Fixtures/Bootstrap/' . $name;
    }

    public function testPacksKernelWithAllElevenAccessorsFromConfigCascade(): void
    {
        $kernel = (new BuildDomainKernelFromEnv())($this->fixturePath());

        // 1: domainRoot() — the packer's configPath doubles as domainRoot.
        self::assertSame($this->fixturePath(), $kernel->domainRoot());

        // 2: env() — proves the two-stage cascade actually applied: the
        // APP_ENV overlay (.env.test) overrides the load()-included value
        // (.env.logger sets DEBUG, .env.test overrides to INFO).
        self::assertSame('INFO', $kernel->env('log_level'));
        self::assertSame('sqlite', $kernel->env('db_driver'));

        // 3: container() — always a Factory instance, never null.
        self::assertInstanceOf(ContainerInterface::class, $kernel->container());

        // 4: cache() — CACHE_LAYERS=memory configured.
        self::assertInstanceOf(CacheInterface::class, $kernel->cache());

        // 5: logger() — LOG_HANDLERS=console configured.
        self::assertInstanceOf(LoggerInterface::class, $kernel->logger());

        // 6+7: eventDispatcher() / eventListenerRegistry() — built unconditionally
        // once jardisadapter/eventdispatcher is installed (D3 pairing).
        self::assertInstanceOf(EventDispatcherInterface::class, $kernel->eventDispatcher());
        self::assertInstanceOf(EventListenerRegistryInterface::class, $kernel->eventListenerRegistry());

        // 8: httpClient() — built unconditionally once jardisadapter/http is installed.
        self::assertInstanceOf(ClientInterface::class, $kernel->httpClient());

        // 9: dbConnection() — sqlite in-memory fallback (no Docker dependency).
        $connection = $kernel->dbConnection();
        self::assertInstanceOf(PDO::class, $connection);
        self::assertSame('sqlite', $connection->getAttribute(PDO::ATTR_DRIVER_NAME));

        // 10: mailer() — MAIL_HOST not configured in the fixture -> documented null.
        self::assertNull($kernel->mailer());

        // 11: filesystem() — built unconditionally once jardisadapter/filesystem is installed.
        self::assertInstanceOf(FilesystemServiceInterface::class, $kernel->filesystem());
    }

    public function testEventDispatcherAndRegistryShareTheSameProviderInstance(): void
    {
        $kernel = (new BuildDomainKernelFromEnv())($this->fixturePath());

        $received = null;
        $kernel->eventListenerRegistry()?->listen(
            \stdClass::class,
            static function (\stdClass $event) use (&$received): void {
                $received = $event;
            },
        );

        $event = new \stdClass();
        $kernel->eventDispatcher()?->dispatch($event);

        self::assertSame($event, $received, 'eventDispatcher() must dispatch through the same provider eventListenerRegistry() registers on (D3 pairing).');
    }

    public function testCacheIsUsableEndToEnd(): void
    {
        $cache = (new BuildDomainKernelFromEnv())($this->fixturePath())->cache();

        self::assertInstanceOf(CacheInterface::class, $cache);
        $cache->set('bootstrap_test_key', 'hello');
        self::assertSame('hello', $cache->get('bootstrap_test_key'));
    }

    public function testMissingConfigDirectoryYieldsEmptyEnvAndGracefulNulls(): void
    {
        $emptyPath = sys_get_temp_dir() . '/jardis-kernel-bootstrap-empty-' . uniqid('', true);
        mkdir($emptyPath);

        try {
            $kernel = (new BuildDomainKernelFromEnv())($emptyPath);

            self::assertSame($emptyPath, $kernel->domainRoot());
            self::assertNull($kernel->env('log_handlers'));

            // No DB_HOST, default driver mysql -> no connection attempted, no exception.
            self::assertNull($kernel->dbConnection());
            // No LOG_HANDLERS -> logger stays null.
            self::assertNull($kernel->logger());
            // No MAIL_HOST -> mailer stays null.
            self::assertNull($kernel->mailer());

            // Adapter-driven services with no ENV guard stay available regardless.
            self::assertInstanceOf(CacheInterface::class, $kernel->cache());
            self::assertInstanceOf(EventDispatcherInterface::class, $kernel->eventDispatcher());
            self::assertInstanceOf(EventListenerRegistryInterface::class, $kernel->eventListenerRegistry());
            self::assertInstanceOf(ClientInterface::class, $kernel->httpClient());
            self::assertInstanceOf(FilesystemServiceInterface::class, $kernel->filesystem());
        } finally {
            rmdir($emptyPath);
        }
    }

    public function testRedisFanOutDegradesGracefullyWhenRedisIsUnreachable(): void
    {
        $path = $this->fixturePath('RedisFanOutUnreachable');

        $kernel = (new BuildDomainKernelFromEnv())($path);

        // REDIS_HOST is set but unreachable -> BuildRedisFromEnv returns null;
        // both CACHE_LAYERS=redis and LOG_HANDLERS=redis must degrade to a
        // working, non-null service without ever seeing an exception —
        // proving the fan-out passes the SAME (null) Redis outcome to both
        // consumers rather than each attempting its own connection.
        self::assertInstanceOf(CacheInterface::class, $kernel->cache());
        self::assertInstanceOf(LoggerInterface::class, $kernel->logger());
    }
}
