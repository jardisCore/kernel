<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Tests\Integration\Bootstrap;

use JardisCore\Kernel\Bootstrap\BuildDomainKernelFromEnv;
use JardisCore\Kernel\Exception\InvalidEnvConfigurationException;
use JardisCore\Kernel\Tests\Support\AuthTokenTable;
use JardisCore\Kernel\Tests\Support\EnvIsolation;
use JardisSupport\Auth\Data\Credential;
use JardisSupport\Auth\Data\HashedToken;
use JardisSupport\Auth\SignedTokenAuthenticator;
use JardisSupport\Contract\Auth\AuthServicesInterface;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/**
 * `auth()` of the packed DomainKernel: AUTH_TOKEN_KEY + the domain's own
 * database connection (SQLite file) -> signed access token, refresh token in
 * the SAME database; no key -> null; bad key -> loud, naming the key.
 */
final class AuthBootstrapTest extends TestCase
{
    use EnvIsolation;

    private string $root;
    private string $dbFile;
    private string $key;

    protected function setUp(): void
    {
        $this->saveProcessEnv();
        $this->key = random_bytes(SODIUM_CRYPTO_AUTH_KEYBYTES);
        $this->root = sys_get_temp_dir() . '/jardis-kernel-auth-' . uniqid('', true);
        mkdir($this->root, 0775, true);
        $this->dbFile = $this->root . '/app.sqlite';

        $setup = new PDO('sqlite:' . $this->dbFile, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        AuthTokenTable::create($setup);

        CountingStatement::$authTokenStatements = 0;
    }

    protected function tearDown(): void
    {
        $this->restoreProcessEnv();
        @unlink($this->dbFile);
        @rmdir($this->root);
    }

    private function envContent(?string $authKey): string
    {
        $env = "DB_DRIVER=sqlite\nDB_PATH={$this->dbFile}\n";

        return $authKey === null ? $env : $env . "AUTH_TOKEN_KEY={$authKey}\n";
    }

    public function testWithoutKeyAuthIsNull(): void
    {
        $kernel = (new BuildDomainKernelFromEnv())($this->root, $this->envContent(null));

        self::assertNull($kernel->auth());
    }

    public function testWithKeyAuthIssuesSignedAccessTokenAndStoresRefreshInTheDomainDatabase(): void
    {
        $kernel = (new BuildDomainKernelFromEnv())($this->root, $this->envContent(base64_encode($this->key)));

        $auth = $kernel->auth();
        self::assertInstanceOf(AuthServicesInterface::class, $auth);

        $result = $auth->sessions()->create('user:1');

        $verified = (new SignedTokenAuthenticator($this->key))
            ->authenticate(Credential::token($result->getAccessToken()));
        self::assertTrue($verified->isSuccess());
        self::assertSame('user:1', $verified->getSubject());

        $row = (new PDO('sqlite:' . $this->dbFile))
            ->query("SELECT token_type, subject FROM auth_tokens WHERE token_hash = '"
                . HashedToken::hashOf($result->getRefreshToken()) . "'")
            ->fetch(PDO::FETCH_ASSOC);
        self::assertSame(['token_type' => 'refresh', 'subject' => 'user:1'], $row);
    }

    public function testNoStatementOnAuthTokensWithoutUsingAuth(): void
    {
        $kernel = (new BuildDomainKernelFromEnv())($this->root, $this->envContent(base64_encode($this->key)));
        $pdo = $kernel->dbConnection();
        self::assertInstanceOf(PDO::class, $pdo);
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [CountingStatement::class]);

        self::assertNotNull($kernel->auth());
        self::assertSame(0, CountingStatement::$authTokenStatements, 'holding auth() must not query the store');

        $kernel->auth()->sessions()->create('user:2');

        self::assertGreaterThan(0, CountingStatement::$authTokenStatements);
    }

    public function testInvalidKeyThrowsNamingTheKey(): void
    {
        $this->expectException(InvalidEnvConfigurationException::class);
        $this->expectExceptionMessage('AUTH_TOKEN_KEY');

        (new BuildDomainKernelFromEnv())($this->root, $this->envContent('bm90LTMyLWJ5dGVz'));
    }
}

/** Counts statements that touch the token table — the observable proof of "no DB access". */
final class CountingStatement extends PDOStatement
{
    public static int $authTokenStatements = 0;

    protected function __construct()
    {
        if (str_contains($this->queryString, 'auth_tokens')) {
            self::$authTokenStatements++;
        }
    }
}
