<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Tests\Integration\Bootstrap;

use JardisCore\Kernel\Bootstrap\BuildDomainKernelFromEnv;
use JardisSupport\Secret\Resolver\AesSecretResolver;
use PHPUnit\Framework\TestCase;

/**
 * Secret resolution through the full Bootstrap-Packer against real temp
 * project roots (Requirement projekt-template-env.md §8): a key file at
 * `<projectRoot>/support/secret.key` turns `secret(...)` ENV values into
 * plaintext; no key file leaves them raw without any error; and the handler
 * is scoped per `__invoke` call — it never leaks between project roots.
 */
final class SecretEnvResolutionTest extends TestCase
{
    /** @var array<string> */
    private array $projectRoots = [];

    private string $key;

    protected function setUp(): void
    {
        $this->key = random_bytes(32);
    }

    protected function tearDown(): void
    {
        foreach ($this->projectRoots as $projectRoot) {
            @unlink($projectRoot . '/config/env/.env');
            @rmdir($projectRoot . '/config/env');
            @rmdir($projectRoot . '/config');
            @unlink($projectRoot . '/support/secret.key');
            @rmdir($projectRoot . '/support');
            @rmdir($projectRoot);
        }
        $this->projectRoots = [];
    }

    private function createProjectRoot(string $envContent, bool $withKeyFile): string
    {
        $projectRoot = sys_get_temp_dir() . '/jardis-kernel-secret-' . uniqid('', true);
        mkdir($projectRoot . '/config/env', 0775, true);
        file_put_contents($projectRoot . '/config/env/.env', $envContent);

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

    public function testMissingKeyFileLeavesSecretValueRawWithoutError(): void
    {
        $cipher = $this->encrypt('never-seen');
        $projectRoot = $this->createProjectRoot(
            'DB_PASSWORD=' . $cipher . "\n",
            withKeyFile: false,
        );

        $kernel = (new BuildDomainKernelFromEnv())($projectRoot);

        self::assertSame($cipher, $kernel->env('db_password'));
    }

    public function testHandlerDoesNotLeakAcrossProjectRootsOnOnePackerInstance(): void
    {
        // One packer, two roots: the first has a key file, the second has
        // none — its secret value must stay raw. A shared DotEnv instance
        // would carry the first root's handler over (accumulation).
        $cipher = $this->encrypt('leak-check');
        $rootWithKey = $this->createProjectRoot(
            'DB_PASSWORD=' . $this->encrypt('first-root-plain') . "\n",
            withKeyFile: true,
        );
        $rootWithoutKey = $this->createProjectRoot(
            'DB_PASSWORD=' . $cipher . "\n",
            withKeyFile: false,
        );

        $packer = new BuildDomainKernelFromEnv();
        $kernelWithKey = $packer($rootWithKey);
        $kernelWithoutKey = $packer($rootWithoutKey);

        self::assertSame('first-root-plain', $kernelWithKey->env('db_password'));
        self::assertSame($cipher, $kernelWithoutKey->env('db_password'));
    }
}
