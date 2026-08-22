<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Tests\Unit\Bootstrap;

use JardisCore\Kernel\Bootstrap\BuildDomainKernelFromEnv;
use JardisCore\Kernel\Exception\InvalidEnvConfigurationException;
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
 * `.env` cascade nested under `config/env/` per the R2 convention) that
 * exercises DotEnv's two-stage cascade (load()-included files + an
 * APP_ENV-specific overlay) — no Docker/real infrastructure required
 * (PRD AC3, Plan P2 AK): DB via SQLite in-memory, no Redis/Mailer configured.
 */
final class BuildDomainKernelFromEnvTest extends TestCase
{
    private function fixturePath(string $name = 'FullConfig'): string
    {
        return __DIR__ . '/../../Fixtures/ProjectRoot/' . $name;
    }

    public function testPacksKernelWithAllElevenAccessorsFromConfigCascade(): void
    {
        $kernel = (new BuildDomainKernelFromEnv())($this->fixturePath());

        // 1: projectRoot() — the packer returns the project root passed in,
        // NOT the internal config/env path it derives and reads from (R2,
        // G1/G8 — resolves the prior accessor-vs-configPath docblock
        // contradiction, BEFUNDE §3).
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

    public function testMissingConfigDirectoryIsCreatedAndYieldsEmptyEnvAndGracefulNulls(): void
    {
        // R2 (G1): the packer takes the PROJECT root, not the config path —
        // a fresh project root has no config/env yet, so the packer creates
        // it itself (race-safe mkdir) instead of throwing or falling back.
        $projectRoot = sys_get_temp_dir() . '/jardis-kernel-bootstrap-empty-' . uniqid('', true);
        mkdir($projectRoot);

        try {
            self::assertDirectoryDoesNotExist($projectRoot . '/config/env');

            $kernel = (new BuildDomainKernelFromEnv())($projectRoot);

            self::assertDirectoryExists(
                $projectRoot . '/config/env',
                'the packer must create the missing config/env directory (G1)',
            );
            self::assertSame($projectRoot, $kernel->projectRoot());
            self::assertNull($kernel->env('log_handlers'));

            // Freshly created, empty config/env -> "not configured"
            // everywhere, same as a pre-existing empty directory (G26).
            self::assertNull($kernel->dbConnection());
            self::assertNull($kernel->logger());
            self::assertNull($kernel->mailer());

            // Adapter-driven services with no ENV guard stay available regardless.
            self::assertInstanceOf(CacheInterface::class, $kernel->cache());
            self::assertInstanceOf(EventDispatcherInterface::class, $kernel->eventDispatcher());
            self::assertInstanceOf(EventListenerRegistryInterface::class, $kernel->eventListenerRegistry());
            self::assertInstanceOf(ClientInterface::class, $kernel->httpClient());
            self::assertInstanceOf(FilesystemServiceInterface::class, $kernel->filesystem());
        } finally {
            @rmdir($projectRoot . '/config/env');
            @rmdir($projectRoot . '/config');
            @rmdir($projectRoot);
        }
    }

    public function testExistingConfigDirectoryIsUsedAsIsWithoutMkdir(): void
    {
        // "Beide Pfadlagen" (AK3.1): config/env already exists (here: empty)
        // — the packer must not need to create it, and must behave exactly
        // like the auto-created case (G26).
        $projectRoot = sys_get_temp_dir() . '/jardis-kernel-bootstrap-preexisting-' . uniqid('', true);
        mkdir($projectRoot . '/config/env', 0775, true);

        try {
            $kernel = (new BuildDomainKernelFromEnv())($projectRoot);

            self::assertSame($projectRoot, $kernel->projectRoot());
            self::assertNull($kernel->dbConnection());
            self::assertNull($kernel->logger());
            self::assertNull($kernel->mailer());
        } finally {
            rmdir($projectRoot . '/config/env');
            rmdir($projectRoot . '/config');
            rmdir($projectRoot);
        }
    }

    public function testMkdirFailureOnUnwritableParentThrows(): void
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            self::markTestSkipped('Running as root bypasses directory permission checks.');
        }

        // G1: mkdir() failure (permissions, read-only filesystem) is the
        // ONE case that throws — everything else about a missing directory
        // degrades gracefully. Two separate mkdir() calls, not one recursive
        // one: a recursive mkdir() applies the restrictive mode to
        // $projectRoot itself too, which would then block creating "config"
        // underneath it for the wrong reason.
        $projectRoot = sys_get_temp_dir() . '/jardis-kernel-bootstrap-readonly-' . uniqid('', true);
        mkdir($projectRoot, 0755);
        mkdir($projectRoot . '/config', 0555);

        try {
            $this->expectException(\RuntimeException::class);

            (new BuildDomainKernelFromEnv())($projectRoot);
        } finally {
            chmod($projectRoot . '/config', 0755);
            rmdir($projectRoot . '/config');
            rmdir($projectRoot);
        }
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
