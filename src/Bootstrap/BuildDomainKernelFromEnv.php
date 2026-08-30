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
use JardisCore\Kernel\Bootstrap\Handler\BuildMessagingFromEnv;
use JardisCore\Kernel\Bootstrap\Handler\BuildRedisFromEnv;
use JardisCore\Kernel\Bootstrap\Handler\AssertNoUnresolvedSecret;
use JardisCore\Kernel\Bootstrap\Handler\BuildSecretHandler;
use JardisCore\Kernel\Bootstrap\Handler\ExtractPdoFromConnection;
use JardisCore\Kernel\Bootstrap\Handler\LoadEnvForKernel;
use JardisCore\Kernel\Bootstrap\Handler\NormalizeEnvKeys;
use JardisCore\Kernel\Bootstrap\Handler\ReadEnvValue;
use JardisCore\Kernel\Bootstrap\Handler\ResolveSecretKeyProvider;
use JardisCore\Kernel\DomainKernel;

/**
 * Packs a {@see DomainKernel} from a project root's `.env` — or from an
 * `.env`-formatted string.
 *
 * The Application-side counterpart to `DomainKernel` itself
 * (Kernel-Entkopplung D4/A6/Z3) — `jardiscore/kernel`'s optional offer for
 * projects that want ENV-driven wiring without adopting a full framework.
 * One invokable class, Tätigkeitsname (`BuildDomainKernelFromEnv`), no
 * `static fromEnv()` (User-Entscheid).
 *
 * Takes the **project root** (the git-clone target). Every configuration value
 * of a Jardis project lives exactly once, in ONE plaintext `.env` in that root
 * — there is no `config/` layer, and this packer never creates a directory.
 * A project root without a `.env` is simply "nothing configured": every
 * service degrades to `null`, no error.
 *
 * Two mutually exclusive input modes, decided inside {@see LoadEnvForKernel}:
 *  - `$envContent === null` — read `<projectRoot>/.env` plus DotEnv's cascade
 *    (`.env` -> `.env.local` -> `.env.{APP_ENV}` and any `load()`/`load?()`
 *    include). Template: `docs/.env.example`.
 *  - a string (INCLUDING `''`) — that string IS the configuration; the file is
 *    not read at all. The dateless container case, where every value arrives
 *    through the process environment.
 *
 * In BOTH modes the process environment wins over the parsed value
 * (jardissupport/dotenv >= 1.4.0) — one 12-factor rule, no kernel-side
 * fallback of its own ({@see DomainKernel::env()} stays file-/string-pure).
 *
 * The resulting kernel's `projectRoot()` returns the project root passed in —
 * the packer builds one kernel per project root; multiple domains sharing one
 * project root share the same `DomainKernel` instance (explicit sharing, G11).
 *
 * Redis fan-out (D4): one Redis connection, built once, feeds both the cache
 * (`redis` layer) and the logger (`redis` handler) — named sub-closures
 * rather than duplicated wiring. The database connection fans out the same
 * way: its writer PDO ({@see ExtractPdoFromConnection}, extracted once) feeds
 * both the optional `db` cache layer and the `database` messaging transport
 * — one stack, one database, never a second DSN to keep in sync.
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
 * Credential-shaped keys ({@see LoadEnvForKernel},
 * `CredentialEnvKeySuffixes`) are registered as DotEnv raw keys before
 * loading — `DB_PASSWORD=false`/`=123456` reach the handlers as the literal
 * string, not a cast `bool`/`int` (R1.2, G6).
 *
 * Secret resolution: the encryption key comes from `APP_SECRET_KEY` in the
 * process environment, else from `<projectRoot>/support/secret.key`
 * ({@see ResolveSecretKeyProvider}); with a key, a `SecretHandler` is
 * prepended to the DotEnv chain so `secret(...)` values decrypt before any
 * cast handler runs. Without any key, an encrypted value cannot be resolved —
 * and instead of passing the cipher on as a value, the boot stops
 * ({@see AssertNoUnresolvedSecret}). DotEnv itself is built fresh per call, so
 * handlers never accumulate across project roots.
 */
final class BuildDomainKernelFromEnv
{
    private readonly Closure $loadEnv;
    private readonly Closure $normalizeEnvKeys;
    private readonly Closure $readEnvValue;
    private readonly Closure $resolveSecretKeyProvider;
    private readonly Closure $buildSecretHandler;
    private readonly Closure $assertNoUnresolvedSecret;
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
        $this->loadEnv = (new LoadEnvForKernel())->__invoke(...);
        $this->normalizeEnvKeys = (new NormalizeEnvKeys())->__invoke(...);
        $this->readEnvValue = (new ReadEnvValue())->__invoke(...);
        $this->resolveSecretKeyProvider = (new ResolveSecretKeyProvider())->__invoke(...);
        $this->buildSecretHandler = (new BuildSecretHandler())->__invoke(...);
        $this->assertNoUnresolvedSecret = (new AssertNoUnresolvedSecret())->__invoke(...);
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

    public function __invoke(string $projectRoot, ?string $envContent = null): DomainKernel
    {
        $secretHandler = ($this->buildSecretHandler)(($this->resolveSecretKeyProvider)($projectRoot));
        $rawEnv = ($this->loadEnv)($projectRoot, $envContent, $secretHandler);

        if ($secretHandler === null) {
            // No key, so nothing could have decrypted: a surviving
            // `secret(...)` value is a config error, checked on the raw keys
            // before any adapter is built.
            ($this->assertNoUnresolvedSecret)($rawEnv);
        }

        $env = ($this->normalizeEnvKeys)($rawEnv);
        $readEnvValue = $this->readEnvValue;
        $envGet = static fn (string $key): mixed => $readEnvValue($env, $key);

        $connection = ($this->buildConnection)($envGet);
        // One extraction, two consumers: the optional `db` cache layer and
        // the database messaging transport both run on the writer PDO the
        // connection above already carries (D4 fan-out, same idea as Redis).
        $writerPdo = ($this->extractPdo)($connection);
        $redis = ($this->buildRedis)($envGet);
        $listenerProvider = ($this->buildEventListenerProvider)();

        return new DomainKernel(
            projectRoot: $projectRoot,
            cache: ($this->buildCache)($envGet, $writerPdo, $redis),
            logger: ($this->buildLogger)($envGet, $redis),
            eventDispatcher: ($this->buildEventDispatcher)($listenerProvider),
            eventListenerRegistry: $listenerProvider,
            httpClient: ($this->buildHttpClient)($envGet),
            connection: $connection,
            mailer: ($this->buildMailer)($envGet),
            filesystem: ($this->buildFilesystem)(),
            env: $env,
            messaging: ($this->buildMessaging)($envGet, $writerPdo),
        );
    }
}
