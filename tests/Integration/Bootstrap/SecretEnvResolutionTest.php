<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Tests\Integration\Bootstrap;

use JardisCore\Kernel\Bootstrap\BuildDomainKernelFromEnv;
use JardisCore\Kernel\Exception\InvalidEnvConfigurationException;
use JardisCore\Kernel\Tests\Support\EnvIsolation;
use JardisSupport\Secret\Resolver\AesSecretResolver;
use PHPUnit\Framework\TestCase;

/**
 * Secret resolution through the full Bootstrap-Packer against real temp
 * project roots: a key file at `<projectRoot>/support/secret.key` turns
 * `secret(...)` ENV values in the root `.env` into plaintext; without any key
 * the O8 guard aborts the bootstrap instead of handing a cipher on as a value;
 * and the handler is scoped per `__invoke` call — it never leaks between
 * project roots.
 *
 * The key chain's first step (`APP_SECRET_KEY`) and the ambient `APP_ENV` are
 * isolated ({@see EnvIsolation}) so nothing outside the fixture decides the
 * outcome.
 */
final class SecretEnvResolutionTest extends TestCase
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
            @unlink($projectRoot . '/.env');
            @unlink($projectRoot . '/support/secret.key');
            @rmdir($projectRoot . '/support');
            @rmdir($projectRoot);
        }
        $this->projectRoots = [];
    }

    private function createProjectRoot(string $envContent, bool $withKeyFile): string
    {
        $projectRoot = sys_get_temp_dir() . '/jardis-kernel-secret-' . uniqid('', true);
        mkdir($projectRoot, 0775, true);
        file_put_contents($projectRoot . '/.env', $envContent);

        if ($withKeyFile) {
            mkdir($projectRoot . '/support', 0775, true);
            file_put_contents($projectRoot . '/support/secret.key', base64_encode($this->key));
        }

        $this->projectRoots[] = $projectRoot;

        return $projectRoot;
    }

    private function encrypt(string $plaintext): string
    {
        return 'secret(' . AesSecretResolver::encrypt($plaintext, $this->key) . ')';
    }

    public function testKeyFileDecryptsCredentialRawKeyToPlaintext(): void
    {
        // DB_PASSWORD is a credential raw key (skips the DotEnv cast chain
        // entirely, R1.2) — secret resolution must still reach it.
        $projectRoot = $this->createProjectRoot(
            'DB_PASSWORD=' . $this->encrypt('pl4in:text/pass') . "\n",
            withKeyFile: true,
        );

        $kernel = (new BuildDomainKernelFromEnv())($projectRoot);

        self::assertSame('pl4in:text/pass', $kernel->env('db_password'));
    }

    public function testDecryptedNonRawKeyStillPassesTheCastChain(): void
    {
        // prepend: true — the secret handler must run BEFORE the cast
        // handlers, so a decrypted "true" still becomes bool true.
        $projectRoot = $this->createProjectRoot(
            'FEATURE_ENABLED=' . $this->encrypt('true') . "\n",
            withKeyFile: true,
        );

        $kernel = (new BuildDomainKernelFromEnv())($projectRoot);

        self::assertTrue($kernel->env('feature_enabled'));
    }

    public function testMissingKeyAbortsInsteadOfPassingTheCipherOn(): void
    {
        // Behaviour change (v2.4.0, O8): a `secret(...)` value that nothing
        // can resolve used to reach the handlers verbatim — a cipher silently
        // used as a password. It is now a loud config error.
        $cipher = $this->encrypt('never-seen');
        $projectRoot = $this->createProjectRoot(
            'DB_PASSWORD=' . $cipher . "\n",
            withKeyFile: false,
        );

        $this->expectException(InvalidEnvConfigurationException::class);

        (new BuildDomainKernelFromEnv())($projectRoot);
    }

    public function testHandlerDoesNotLeakAcrossProjectRootsOnOnePackerInstance(): void
    {
        // One packer, two roots: the first has a key file, the second has
        // none — so the second must NOT decrypt. A shared DotEnv instance
        // would carry the first root's handler over (accumulation) and
        // quietly resolve the cipher; with the handler correctly scoped per
        // call, the second root has no handler at all and the O8 guard fires.
        $rootWithKey = $this->createProjectRoot(
            'DB_PASSWORD=' . $this->encrypt('first-root-plain') . "\n",
            withKeyFile: true,
        );
        $rootWithoutKey = $this->createProjectRoot(
            'DB_PASSWORD=' . $this->encrypt('leak-check') . "\n",
            withKeyFile: false,
        );

        $packer = new BuildDomainKernelFromEnv();
        $kernelWithKey = $packer($rootWithKey);

        self::assertSame('first-root-plain', $kernelWithKey->env('db_password'));

        $this->expectException(InvalidEnvConfigurationException::class);

        $packer($rootWithoutKey);
    }
}
