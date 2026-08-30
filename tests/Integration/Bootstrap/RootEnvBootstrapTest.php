<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Tests\Integration\Bootstrap;

use JardisCore\Kernel\Bootstrap\BuildDomainKernelFromEnv;
use JardisCore\Kernel\Exception\InvalidEnvConfigurationException;
use JardisCore\Kernel\Tests\Support\EnvIsolation;
use JardisAdapter\Http\Config\ClientConfig;
use JardisAdapter\Http\HttpClient;
use JardisSupport\Secret\Resolver\AesSecretResolver;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\SimpleCache\CacheInterface;
use ReflectionProperty;

/**
 * PRD-V2 §4 K.9 (a)-(s) — the Bootstrap-Packer against the ONE `.env` in the
 * project root — the one configuration file —, the string input that replaces
 * it, the process-environment precedence dotenv 1.4.0 introduced, and the
 * two-step secret key chain (`APP_SECRET_KEY` -> `<root>/support/secret.key`)
 * with its O8 guard.
 *
 * Every test isolates `APP_ENV`, `APP_SECRET_KEY` and `JARDIS_DOTENV_VARS`
 * ({@see EnvIsolation}) — the phpcli image exports `APP_ENV=dev`, which since
 * dotenv 1.4.0 beats a fixture's own value.
 */
final class RootEnvBootstrapTest extends TestCase
{
    use EnvIsolation;

    /** @var array<string> */
    private array $projectRoots = [];

    private string $key;

    protected function setUp(): void
    {
        $this->saveProcessEnv();
        $this->key = random_bytes(32);
    }

    protected function tearDown(): void
    {
        $this->restoreProcessEnv();

        foreach ($this->projectRoots as $projectRoot) {
            $this->removeTree($projectRoot);
        }
        $this->projectRoots = [];
    }

    private function removeTree(string $path): void
    {
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->removeTree($path . '/' . $entry);
                }
            }
            @rmdir($path);

            return;
        }

        @unlink($path);
    }

    /**
     * @param array<string, string> $files relative path => content
     */
    private function makeRoot(array $files): string
    {
        $projectRoot = sys_get_temp_dir() . '/jardis-kernel-rootenv-' . uniqid('', true);
        mkdir($projectRoot, 0775, true);
        $this->projectRoots[] = $projectRoot;

        foreach ($files as $relative => $content) {
            $target = $projectRoot . '/' . $relative;
            $directory = dirname($target);
            if (!is_dir($directory)) {
                mkdir($directory, 0775, true);
            }
            file_put_contents($target, $content);
        }

        return $projectRoot;
    }

    private function encrypt(string $plaintext, ?string $key = null): string
    {
        return 'secret(' . AesSecretResolver::encrypt($plaintext, $key ?? $this->key) . ')';
    }

    // (a)
    public function testK9a_RootEnvFileIsLoaded(): void
    {
        $projectRoot = $this->makeRoot(['.env' => "LOG_CONTEXT=from-root-env\n"]);

        $kernel = (new BuildDomainKernelFromEnv())($projectRoot);

        self::assertSame('from-root-env', $kernel->env('log_context'));
        self::assertSame($projectRoot, $kernel->projectRoot());
    }

    // (b)
    public function testK9b_LocalOverlayWinsOverRootEnv(): void
    {
        $projectRoot = $this->makeRoot([
            '.env' => "LOG_CONTEXT=base\n",
            '.env.local' => "LOG_CONTEXT=local\n",
        ]);

        $kernel = (new BuildDomainKernelFromEnv())($projectRoot);

        self::assertSame('local', $kernel->env('log_context'));
    }

    // (c)
    public function testK9c_StringInputIsExclusiveAgainstTheFile(): void
    {
        $projectRoot = $this->makeRoot(['.env' => "FILE_ONLY_KEY=from-file\n"]);

        $kernel = (new BuildDomainKernelFromEnv())($projectRoot, "STRING_KEY=from-string\n");

        self::assertSame('from-string', $kernel->env('string_key'));
        self::assertNull($kernel->env('file_only_key'), 'the root .env must not be read at all in string mode (O10)');
    }

    // (d)
    public function testK9d_ProcessEnvironmentWinsOverTheFile(): void
    {
        $projectRoot = $this->makeRoot(['.env' => "LOG_CONTEXT=from-file\n"]);
        $this->setProcessEnv('LOG_CONTEXT', 'from-environment');

        $kernel = (new BuildDomainKernelFromEnv())($projectRoot);

        self::assertSame('from-environment', $kernel->env('log_context'));
    }

    // (e)
    public function testK9e_AppSecretKeyDecryptsWithoutAKeyFile(): void
    {
        $projectRoot = $this->makeRoot(['.env' => 'DB_PASSWORD=' . $this->encrypt('pl4in:pass') . "\n"]);
        $this->setProcessEnv('APP_SECRET_KEY', base64_encode($this->key));

        $kernel = (new BuildDomainKernelFromEnv())($projectRoot);

        self::assertSame('pl4in:pass', $kernel->env('db_password'));
    }

    // (f)
    public function testK9f_KeyFileDecryptsWithoutAppSecretKey(): void
    {
        $projectRoot = $this->makeRoot([
            '.env' => 'DB_PASSWORD=' . $this->encrypt('from-key-file') . "\n",
            'support/secret.key' => base64_encode($this->key),
        ]);

        $kernel = (new BuildDomainKernelFromEnv())($projectRoot);

        self::assertSame('from-key-file', $kernel->env('db_password'));
    }

    // (g)
    public function testK9g_AppSecretKeyWinsOverTheKeyFile(): void
    {
        // The key file carries a DIFFERENT key: if it were consulted, the
        // resolver chain would fail to decrypt instead of returning the
        // plaintext below — that is what makes "ENV wins" observable.
        $projectRoot = $this->makeRoot([
            '.env' => 'DB_PASSWORD=' . $this->encrypt('env-key-won') . "\n",
            'support/secret.key' => base64_encode(random_bytes(32)),
        ]);
        $this->setProcessEnv('APP_SECRET_KEY', base64_encode($this->key));

        $kernel = (new BuildDomainKernelFromEnv())($projectRoot);

        self::assertSame('env-key-won', $kernel->env('db_password'));
    }

    // (h)
    public function testK9h_CipherWithoutAnyKeyThrowsNamingTheKeyNotTheValue(): void
    {
        $cipher = $this->encrypt('never-seen');
        $projectRoot = $this->makeRoot(['.env' => 'DB_PASSWORD=' . $cipher . "\n"]);

        try {
            (new BuildDomainKernelFromEnv())($projectRoot);
            self::fail('an unresolved secret(...) value must abort the bootstrap (O8)');
        } catch (InvalidEnvConfigurationException $exception) {
            self::assertStringContainsString('DB_PASSWORD', $exception->getMessage());
            self::assertStringContainsString('APP_SECRET_KEY', $exception->getMessage());
            self::assertStringNotContainsString($cipher, $exception->getMessage());
        }
    }

    // (i)
    public function testK9i_BootstrapCreatesNoConfigDirectory(): void
    {
        $projectRoot = $this->makeRoot(['.env' => "LOG_CONTEXT=no-config-dir\n"]);

        (new BuildDomainKernelFromEnv())($projectRoot);

        self::assertDirectoryDoesNotExist($projectRoot . '/config', 'the packer must never create config/ again (Kurs V2)');
    }

    // (j)
    public function testK9j_ProjectRootWithoutEnvFileDegradesGracefully(): void
    {
        $projectRoot = $this->makeRoot([]);

        $kernel = (new BuildDomainKernelFromEnv())($projectRoot);

        self::assertNull($kernel->env('log_handlers'));
        self::assertNull($kernel->dbConnection());
        self::assertNull($kernel->logger());
        self::assertNull($kernel->mailer());
        self::assertInstanceOf(CacheInterface::class, $kernel->cache());
        self::assertInstanceOf(ClientInterface::class, $kernel->httpClient());
        // ... and it stays a bare directory: no config/ is conjured up for it.
        self::assertDirectoryDoesNotExist($projectRoot . '/config');
    }

    // (k)
    public function testK9k_WhitespaceOnlyAppSecretKeyFallsBackToTheKeyFile(): void
    {
        $projectRoot = $this->makeRoot([
            '.env' => 'DB_PASSWORD=' . $this->encrypt('whitespace-fallback') . "\n",
            'support/secret.key' => base64_encode($this->key),
        ]);
        $this->setProcessEnv('APP_SECRET_KEY', '   ');

        $kernel = (new BuildDomainKernelFromEnv())($projectRoot);

        self::assertSame('whitespace-fallback', $kernel->env('db_password'));
    }

    // (l)
    public function testK9l_GuardStaysSilentWhileAHandlerIsActive(): void
    {
        $projectRoot = $this->makeRoot([
            '.env' => 'FEATURE_TOKEN=' . $this->encrypt('resolved-token') . "\n",
            'support/secret.key' => base64_encode($this->key),
        ]);

        $kernel = (new BuildDomainKernelFromEnv())($projectRoot);

        self::assertSame('resolved-token', $kernel->env('feature_token'));
    }

    // (m)
    public function testK9m_SecretIsResolvedInStringMode(): void
    {
        $projectRoot = $this->makeRoot(['support/secret.key' => base64_encode($this->key)]);

        $kernel = (new BuildDomainKernelFromEnv())(
            $projectRoot,
            'DB_PASSWORD=' . $this->encrypt('string-mode-secret') . "\n",
        );

        self::assertSame('string-mode-secret', $kernel->env('db_password'));
    }

    // (n)
    public function testK9n_CredentialRawKeyStaysAStringInStringMode(): void
    {
        $projectRoot = $this->makeRoot([]);

        $kernel = (new BuildDomainKernelFromEnv())($projectRoot, "DB_PASSWORD=123456\n");

        self::assertSame('123456', $kernel->env('db_password'));
    }

    // (o)
    public function testK9o_ProcessEnvironmentWinsOverTheString(): void
    {
        $projectRoot = $this->makeRoot([]);
        $this->setProcessEnv('LOG_CONTEXT', 'from-environment');

        $kernel = (new BuildDomainKernelFromEnv())($projectRoot, "LOG_CONTEXT=from-string\n");

        self::assertSame('from-environment', $kernel->env('log_context'));
    }

    // (p)
    public function testK9p_AmbientAppEnvSelectsTheCascadeOverlay(): void
    {
        $projectRoot = $this->makeRoot([
            '.env' => "APP_ENV=test\nLOG_CONTEXT=base\n",
            '.env.test' => "LOG_CONTEXT=test-overlay\n",
            '.env.prod' => "LOG_CONTEXT=prod-overlay\n",
        ]);
        $this->setProcessEnv('APP_ENV', 'prod');

        $kernel = (new BuildDomainKernelFromEnv())($projectRoot);

        self::assertSame('prod-overlay', $kernel->env('log_context'));
        self::assertSame('prod', $kernel->env('app_env'));
    }

    // (q)
    public function testK9q_FileSuffixPathResolvesRelativeToTheProjectRootInStringMode(): void
    {
        $projectRoot = $this->makeRoot(['support/db_password.txt' => "file-borne-secret\n"]);

        $kernel = (new BuildDomainKernelFromEnv())($projectRoot, "DB_PASSWORD_FILE=support/db_password.txt\n");

        self::assertSame('file-borne-secret', $kernel->env('db_password'));
    }

    // (r)
    public function testK9r_GuardFiresBeforeAnyAdapterIsBuilt(): void
    {
        // DB_HOST carries the cipher: without the guard the packer would run
        // into a connection failure (also an InvalidEnvConfigurationException)
        // — the message is what tells the two apart.
        $projectRoot = $this->makeRoot([
            '.env' => "DB_DRIVER=mysql\nDB_HOST=" . $this->encrypt('db.internal') . "\n",
        ]);

        try {
            (new BuildDomainKernelFromEnv())($projectRoot);
            self::fail('the O8 guard must abort before the connection is attempted');
        } catch (InvalidEnvConfigurationException $exception) {
            self::assertStringContainsString('DB_HOST', $exception->getMessage());
            self::assertStringContainsString('APP_SECRET_KEY', $exception->getMessage());
        }
    }

    /**
     * Not one of (a)-(s), but the guard for a deliberate design decision in
     * {@see \JardisCore\Kernel\Bootstrap\Handler\ReadEnvValue}: the packer's
     * key lookup must NOT read the cast `bool(false)` as "unset". Delegating
     * it to `IsEnvValueUnset` (which does, correctly, for string-shaped keys)
     * would silently turn `HTTP_VERIFY_SSL=false` back into the `true`
     * default — invisible to every other test in this suite, which reaches
     * the Handlers through `LoadEnvGetFromRealCascade` rather than the packer.
     */
    public function testExplicitFalseSurvivesThePackerEnvLookup(): void
    {
        $projectRoot = $this->makeRoot(['.env' => "HTTP_VERIFY_SSL=false\n"]);

        $client = (new BuildDomainKernelFromEnv())($projectRoot)->httpClient();
        self::assertInstanceOf(HttpClient::class, $client);

        /** @var ClientConfig $config */
        $config = (new ReflectionProperty(HttpClient::class, 'config'))->getValue($client);
        self::assertFalse($config->verifySsl, 'HTTP_VERIFY_SSL=false must reach the client as false, not as "unset"');
    }

    // (s)
    public function testK9s_EmptyStringIsAValidStringModeInput(): void
    {
        $projectRoot = $this->makeRoot(['.env' => "FILE_ONLY_KEY=from-file\nDB_DRIVER=sqlite\nDB_PATH=:memory:\n"]);

        // Control first: in file mode the very same root DOES yield the key —
        // so the null below is the string mode's exclusivity, not an
        // unreadable fixture.
        $fileMode = (new BuildDomainKernelFromEnv())($projectRoot);
        self::assertSame('from-file', $fileMode->env('file_only_key'));

        $kernel = (new BuildDomainKernelFromEnv())($projectRoot, '');

        self::assertNull($kernel->env('file_only_key'), 'an empty string is string mode, not "fall back to the file"');
        self::assertNull($kernel->dbConnection());
    }
}
