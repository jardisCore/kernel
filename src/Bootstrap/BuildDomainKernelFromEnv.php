<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Bootstrap;

use Closure;
use JardisCore\Kernel\Bootstrap\Data\CredentialEnvKeySuffixes;
use JardisCore\Kernel\Bootstrap\Handler\BuildCacheFromEnv;
use JardisCore\Kernel\Bootstrap\Handler\BuildConnectionFromEnv;
use JardisCore\Kernel\Bootstrap\Handler\BuildEventDispatcherFromProvider;
use JardisCore\Kernel\Bootstrap\Handler\BuildEventListenerProviderFromEnv;
use JardisCore\Kernel\Bootstrap\Handler\BuildFilesystemFromEnv;
use JardisCore\Kernel\Bootstrap\Handler\BuildHttpClientFromEnv;
use JardisCore\Kernel\Bootstrap\Handler\BuildLoggerFromEnv;
use JardisCore\Kernel\Bootstrap\Handler\BuildMailerFromEnv;
use JardisCore\Kernel\Bootstrap\Handler\BuildMessagingFromEnv;
use JardisCore\Kernel\Bootstrap\Handler\BuildRedisFromEnv;
use JardisCore\Kernel\Bootstrap\Handler\ExtractPdoFromConnection;
use JardisCore\Kernel\DomainKernel;
use JardisSupport\DotEnv\DotEnv;
use RuntimeException;

/**
 * Packs a {@see DomainKernel} from a config path's cascading `.env` files.
 *
 * The Application-side counterpart to `DomainKernel` itself
 * (Kernel-Entkopplung D4/A6/Z3) — `jardiscore/kernel`'s optional offer for
 * projects that want ENV-driven wiring without adopting a full framework.
 * One invokable class, Tätigkeitsname (`BuildDomainKernelFromEnv`), no
 * `static fromEnv()` (User-Entscheid).
 *
 * Takes the **project root** (the git-clone target), not a config path —
 * the fixed convention is `<projectRoot>/config/env` (R2, G1/G2/G8). Missing?
 * The packer creates it (`mkdir`, race-safe against a parallel fpm cold
 * start); an empty or freshly created directory degrades every service to
 * `null` like any other unconfigured ENV. An `mkdir` failure (permissions,
 * read-only filesystem) is the only case that throws — everything else
 * about a missing directory is "not configured", not an error.
 *
 * ENV loading uses `DotEnv::loadPrivate()` — the same `load()`/`load?()`
 * cascade every other Jardis config file understands (templates:
 * `docs/env-examples/`). The resulting kernel's `projectRoot()` returns the
 * project root passed in here, not the internal `config/env` path — the
 * packer builds one kernel per project root; multiple domains sharing one
 * project root share the same `DomainKernel` instance (explicit sharing,
 * G11 — no more implicit first-write-wins registry).
 *
 * Redis fan-out (D4): one Redis connection, built once, feeds both the cache
 * (`redis` layer) and the logger (`redis` handler) — named sub-closures
 * rather than duplicated wiring.
 *
 * All 7 adapter packages this packer can use are optional (composer
 * `suggest`); every Handler closure degrades to `null` via `class_exists()`
 * guards when its adapter is not installed or its ENV is not configured —
 * the resulting `DomainKernel` simply carries `null` for that service.
 *
 * Container wiring is intentionally out of scope (G6, "kein Problem, kein
 * Pattern") — the packed kernel's `container()` is the bare `Factory`
 * fallback; callers needing a custom PSR-11 container build their own
 * `DomainKernel` directly.
 *
 * Credential-shaped keys ({@see CredentialEnvKeySuffixes}) are registered as
 * DotEnv raw keys before loading — `DB_PASSWORD=false`/`=123456` reach the
 * handlers as the literal string, not a cast `bool`/`int` (R1.2, G6).
 */
final class BuildDomainKernelFromEnv
{
    private readonly Closure $loadEnv;
    private readonly Closure $buildConnection;
    private readonly Closure $extractPdo;
    private readonly Closure $buildRedis;
    private readonly Closure $buildCache;
    private readonly Closure $buildLogger;
    private readonly Closure $buildEventListenerProvider;
    private readonly Closure $buildEventDispatcher;
    private readonly Closure $buildHttpClient;
    private readonly Closure $buildMailer;
    private readonly Closure $buildFilesystem;
    private readonly Closure $buildMessaging;

    public function __construct()
    {
        $dotEnv = new DotEnv();
        $dotEnv->addRawKeys(CredentialEnvKeySuffixes::SUFFIXES);
        $this->loadEnv = $dotEnv->loadPrivate(...);
        $this->buildConnection = (new BuildConnectionFromEnv())->__invoke(...);
        $this->extractPdo = (new ExtractPdoFromConnection())->__invoke(...);
        $this->buildRedis = (new BuildRedisFromEnv())->__invoke(...);
        $this->buildCache = (new BuildCacheFromEnv())->__invoke(...);
        $this->buildLogger = (new BuildLoggerFromEnv())->__invoke(...);
        $this->buildEventListenerProvider = (new BuildEventListenerProviderFromEnv())->__invoke(...);
        $this->buildEventDispatcher = (new BuildEventDispatcherFromProvider())->__invoke(...);
        $this->buildHttpClient = (new BuildHttpClientFromEnv())->__invoke(...);
        $this->buildMailer = (new BuildMailerFromEnv())->__invoke(...);
        $this->buildFilesystem = (new BuildFilesystemFromEnv())->__invoke(...);
        $this->buildMessaging = (new BuildMessagingFromEnv())->__invoke(...);
    }

    public function __invoke(string $projectRoot): DomainKernel
    {
        $configPath = $projectRoot . '/config/env';

        // Race-safe: a parallel fpm cold start may create the directory
        // between the is_dir() check and mkdir() — re-check after a failed
        // mkdir() before treating it as a real failure (TOCTOU, G1).
        if (!is_dir($configPath) && !@mkdir($configPath, 0775, true) && !is_dir($configPath)) {
            throw new RuntimeException(sprintf('Failed to create config directory "%s".', $configPath));
        }

        $env = array_change_key_case(($this->loadEnv)($configPath), CASE_LOWER);
        $envGet = static function (string $key) use ($env): mixed {
            $value = $env[strtolower($key)] ?? null;
            // An explicitly empty value (`KEY=`) is "missing", not "set to
            // the empty string" — one place instead of every Handler doing
            // its own '' check (R1.3 rule 1, uniform across all handlers).
            // No global-process-environment fallback (R3, G16) — file-only,
            // see DomainKernel::env().
            return $value === '' ? null : $value;
        };

        $connection = ($this->buildConnection)($envGet);
        $redis = ($this->buildRedis)($envGet);
        $listenerProvider = ($this->buildEventListenerProvider)();

        return new DomainKernel(
            projectRoot: $projectRoot,
            cache: ($this->buildCache)($envGet, ($this->extractPdo)($connection), $redis),
            logger: ($this->buildLogger)($envGet, $redis),
            eventDispatcher: ($this->buildEventDispatcher)($listenerProvider),
            eventListenerRegistry: $listenerProvider,
            httpClient: ($this->buildHttpClient)($envGet),
            connection: $connection,
            mailer: ($this->buildMailer)($envGet),
            filesystem: ($this->buildFilesystem)(),
            env: $env,
            messaging: ($this->buildMessaging)($envGet),
        );
    }
}
