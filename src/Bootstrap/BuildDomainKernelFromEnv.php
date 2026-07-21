<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Bootstrap;

use Closure;
use JardisCore\Kernel\Bootstrap\Handler\BuildCacheFromEnv;
use JardisCore\Kernel\Bootstrap\Handler\BuildConnectionFromEnv;
use JardisCore\Kernel\Bootstrap\Handler\BuildEventDispatcherFromProvider;
use JardisCore\Kernel\Bootstrap\Handler\BuildEventListenerProviderFromEnv;
use JardisCore\Kernel\Bootstrap\Handler\BuildFilesystemFromEnv;
use JardisCore\Kernel\Bootstrap\Handler\BuildHttpClientFromEnv;
use JardisCore\Kernel\Bootstrap\Handler\BuildLoggerFromEnv;
use JardisCore\Kernel\Bootstrap\Handler\BuildMailerFromEnv;
use JardisCore\Kernel\Bootstrap\Handler\BuildRedisFromEnv;
use JardisCore\Kernel\Bootstrap\Handler\ExtractPdoFromConnection;
use JardisCore\Kernel\DomainKernel;
use JardisSupport\DotEnv\DotEnv;

/**
 * Packs a {@see DomainKernel} from a config path's cascading `.env` files.
 *
 * The Application-side counterpart to the Koffer (`DomainKernel`) itself
 * (Kernel-Entkopplung D4/A6/Z3) — `jardiscore/kernel`'s optional offer for
 * projects that want ENV-driven wiring without adopting a full framework.
 * One invokable class, Tätigkeitsname (`BuildDomainKernelFromEnv`), no
 * `static fromEnv()` (User-Entscheid).
 *
 * ENV loading uses `DotEnv::loadPrivate()` — the same `load()`/`load?()`
 * cascade every other Jardis config file understands (templates:
 * `docs/env-examples/`). `$configPath` doubles as the resulting kernel's
 * `domainRoot()` — the packer builds one kernel per config root; multiple
 * domains sharing one config path share the same `DomainKernel` instance
 * (explicit sharing, G11 — no more implicit first-write-wins registry).
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

    public function __construct()
    {
        $this->loadEnv = (new DotEnv())->loadPrivate(...);
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
    }

    public function __invoke(string $configPath): DomainKernel
    {
        $env = array_change_key_case(($this->loadEnv)($configPath), CASE_LOWER);
        $envGet = static fn (string $key): mixed => $env[strtolower($key)] ?? $_ENV[strtolower($key)] ?? null;

        $connection = ($this->buildConnection)($envGet);
        $redis = ($this->buildRedis)($envGet);
        $listenerProvider = ($this->buildEventListenerProvider)();

        return new DomainKernel(
            domainRoot: $configPath,
            cache: ($this->buildCache)($envGet, ($this->extractPdo)($connection), $redis),
            logger: ($this->buildLogger)($envGet, $redis),
            eventDispatcher: ($this->buildEventDispatcher)($listenerProvider),
            eventListenerRegistry: $listenerProvider,
            httpClient: ($this->buildHttpClient)($envGet),
            connection: $connection,
            mailer: ($this->buildMailer)($envGet),
            filesystem: ($this->buildFilesystem)(),
            env: $env,
        );
    }
}
