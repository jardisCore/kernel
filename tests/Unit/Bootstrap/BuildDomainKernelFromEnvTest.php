<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Tests\Unit\Bootstrap;

use JardisCore\Kernel\Bootstrap\BuildDomainKernelFromEnv;
use JardisCore\Kernel\Exception\InvalidEnvConfigurationException;
use JardisCore\Kernel\Tests\Support\EnvIsolation;
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
 * Uses a project-root fixture (tests/Fixtures/ProjectRoot/FullConfig/, its
 * `.env` cascade directly in the project ROOT, the one configuration file)
 * that exercises DotEnv's two-stage cascade (load()-included files + an
 * APP_ENV-specific overlay) — no Docker/real infrastructure required
 * (PRD AC3, Plan P2 AK): DB via SQLite in-memory, no Redis/Mailer configured.
 *
 * `APP_ENV` is isolated ({@see EnvIsolation}): the phpcli image exports
 * `APP_ENV=dev`, and since dotenv 1.4.0 that ambient value beats the
 * fixture's own `APP_ENV=test` — which would silently pick a different
 * overlay than this fixture describes.
 */
final class BuildDomainKernelFromEnvTest extends TestCase
{
    use EnvIsolation;

    protected function setUp(): void
    {
        $this->saveProcessEnv();
    }

    protected function tearDown(): void
    {
        $this->restoreProcessEnv();
    }

    private function fixturePath(string $name = 'FullConfig'): string
    {
        return __DIR__ . '/../../Fixtures/ProjectRoot/' . $name;
    }

    public function testPacksKernelWithAllElevenAccessorsFromConfigCascade(): void
    {
        $kernel = (new BuildDomainKernelFromEnv())($this->fixturePath());

        // 1: projectRoot() — the packer returns the project root passed in,
        // which is also the directory its `.env` cascade is read from
        // (Kurs V2: one `.env` in the root, no derived config path).
        self::assertSame($this->fixturePath(), $kernel->projectRoot());

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

    public function testRedisFanOutRaisesWhenRedisIsConfiguredButUnreachable(): void
    {
        // R1.3 rule 3 (G5): REDIS_HOST is set but unreachable — a configured,
        // unreachable service now throws instead of BuildRedisFromEnv
        // silently returning null to both cache and logger (behaviour change,
        // CHANGELOG.md). See UnreachableServiceExceptionTest for the
        // dedicated Integration coverage (PLAN.md AK2.5).
        $this->expectException(InvalidEnvConfigurationException::class);

        (new BuildDomainKernelFromEnv())($this->fixturePath('RedisFanOutUnreachable'));
    }
}
