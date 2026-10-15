<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Tests\Unit\Bootstrap\Handler;

use Closure;
use JardisAdapter\DbConnection\Factory\ConnectionFactory;
use JardisCore\Kernel\Bootstrap\Handler\BuildAuthServicesFromEnv;
use JardisCore\Kernel\Bootstrap\LazyAuthServices;
use JardisCore\Kernel\Exception\InvalidEnvConfigurationException;
use JardisCore\Kernel\Tests\Support\AuthTokenTable;
use JardisSupport\Contract\Auth\AuthServicesInterface;
use JardisSupport\Contract\DbConnection\ConnectionPoolInterface;
use JardisSupport\Contract\DbConnection\DbConnectionInterface;
use PDO;
use PHPUnit\Framework\TestCase;

final class BuildAuthServicesFromEnvTest extends TestCase
{
    private string $key;

    protected function setUp(): void
    {
        $this->key = random_bytes(SODIUM_CRYPTO_AUTH_KEYBYTES);
    }

    public function testWithoutKeyThereIsNoAuth(): void
    {
        self::assertNull((new BuildAuthServicesFromEnv())($this->envFrom([]), $this->sqlite()));
    }

    /** DotEnv casts an empty `AUTH_TOKEN_KEY=` to bool(false) — that is "not configured". */
    public function testEmptyKeyCastToFalseIsNotConfigured(): void
    {
        self::assertNull((new BuildAuthServicesFromEnv())($this->envFrom(['auth_token_key' => false]), $this->sqlite()));
    }

    public function testMissingAuthPackageYieldsNullEvenWithKey(): void
    {
        $handler = new BuildAuthServicesFromEnv(static fn (): bool => false);

        self::assertNull($handler($this->envFrom(['auth_token_key' => base64_encode($this->key)]), $this->sqlite()));
    }

    public function testKeyThatIsNotBase64ThrowsNamingTheKey(): void
    {
        $this->expectException(InvalidEnvConfigurationException::class);
        $this->expectExceptionMessage('AUTH_TOKEN_KEY');

        (new BuildAuthServicesFromEnv())($this->envFrom(['auth_token_key' => '%%% not base64 %%%']), $this->sqlite());
    }

    public function testKeyOfWrongLengthThrowsNamingTheKey(): void
    {
        $this->expectException(InvalidEnvConfigurationException::class);
        $this->expectExceptionMessage('AUTH_TOKEN_KEY');

        (new BuildAuthServicesFromEnv())($this->envFrom(['auth_token_key' => base64_encode('too-short')]), $this->sqlite());
    }

    public function testKeyWithoutDatabaseThrowsNamingTheKey(): void
    {
        $this->expectException(InvalidEnvConfigurationException::class);
        $this->expectExceptionMessage('AUTH_TOKEN_KEY');

        (new BuildAuthServicesFromEnv())($this->envFrom(['auth_token_key' => base64_encode($this->key)]), null);
    }

    public function testPdoConnectionIsBridgedToTheTokenStore(): void
    {
        $pdo = $this->sqlite();

        $auth = (new BuildAuthServicesFromEnv())($this->envFrom(['auth_token_key' => base64_encode($this->key)]), $pdo);

        self::assertInstanceOf(LazyAuthServices::class, $auth);
        $result = $auth->sessions()->create('user:7');
        self::assertSame(
            1,
            (int) $pdo->query("SELECT COUNT(*) FROM auth_tokens WHERE subject = 'user:7'")->fetchColumn(),
        );
        self::assertNotSame('', $result->getAccessToken());
    }

    public function testPoolWriterIsResolvedOnlyOnFirstUse(): void
    {
        $pdo = $this->sqlite();
        $writer = (new ConnectionFactory())->fromPdo($pdo);
        $pool = new class ($writer) implements ConnectionPoolInterface {
            public int $writerCalls = 0;

            public function __construct(private readonly DbConnectionInterface $writer)
            {
            }

            public function getWriter(): DbConnectionInterface
            {
                $this->writerCalls++;

                return $this->writer;
            }

            public function getReader(): DbConnectionInterface
            {
                return $this->writer;
            }

            public function getReaders(): array
            {
                return [$this->writer];
            }

            public function getReaderCount(): int
            {
                return 1;
            }

            public function getStats(): array
            {
                return [];
            }

            public function resetStats(): void
            {
            }
        };

        $auth = (new BuildAuthServicesFromEnv())($this->envFrom(['auth_token_key' => base64_encode($this->key)]), $pool);

        self::assertInstanceOf(AuthServicesInterface::class, $auth);
        self::assertSame(0, $pool->writerCalls, 'building the services must not touch the pool');

        $auth->sessions()->create('user:1');

        self::assertSame(1, $pool->writerCalls);
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM auth_tokens')->fetchColumn());
    }

    private function sqlite(): PDO
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        AuthTokenTable::create($pdo);

        return $pdo;
    }

    /**
     * @param array<string, mixed> $values
     * @return Closure(string): mixed
     */
    private function envFrom(array $values): Closure
    {
        return static fn (string $key): mixed => $values[$key] ?? null;
    }
}
