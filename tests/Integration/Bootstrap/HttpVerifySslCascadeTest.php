<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Tests\Integration\Bootstrap;

use JardisAdapter\Http\Config\ClientConfig;
use JardisAdapter\Http\HttpClient;
use JardisCore\Kernel\Bootstrap\Handler\BuildHttpClientFromEnv;
use JardisCore\Kernel\Exception\InvalidEnvConfigurationException;
use JardisCore\Kernel\Tests\Support\LoadEnvGetFromRealCascade;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * AK2.1/AK2.2 (PLAN.md §P2) — `HTTP_VERIFY_SSL` through a REAL DotEnv
 * cascade, not the Roh-String closures the old
 * `BuildHttpClientFromEnvTest` fed directly (BEFUNDE.md §1c).
 *
 * Gegenprobe: with the pre-R1 code (`($env('http_verify_ssl') ?? 'true') ===
 * 'true'`), `HTTP_VERIFY_SSL=true` reaches the handler as the DotEnv-cast
 * `bool(true)`, and `bool(true) === 'true'` is `false` in PHP — so
 * `testTrueLiteralKeepsSslVerificationOn` below would fail (verifySsl ends
 * up `false`, the exact security bug BEFUNDE.md §1c documents) against that
 * code. `HttpClient` has no public accessor for its `ClientConfig`, so this
 * test reads the private property via Reflection — narrowly, only to
 * observe an otherwise-unobservable constructor argument, not to mock.
 */
final class HttpVerifySslCascadeTest extends TestCase
{
    private function fixturePath(string $name): string
    {
        return __DIR__ . '/../../Fixtures/Bootstrap/' . $name;
    }

    private function verifySslOf(HttpClient $client): bool
    {
        $property = new ReflectionProperty(HttpClient::class, 'config');
        /** @var ClientConfig $config */
        $config = $property->getValue($client);

        return $config->verifySsl;
    }

    public function testTrueLiteralKeepsSslVerificationOn(): void
    {
        $env = (new LoadEnvGetFromRealCascade())($this->fixturePath('BoolCasting/TrueLiteral'));
        $client = (new BuildHttpClientFromEnv())($env);

        self::assertInstanceOf(HttpClient::class, $client);
        self::assertTrue($this->verifySslOf($client), 'HTTP_VERIFY_SSL=true must keep SSL verification ON.');
    }

    public function testFalseLiteralTurnsSslVerificationOff(): void
    {
        $env = (new LoadEnvGetFromRealCascade())($this->fixturePath('BoolCasting/FalseLiteral'));
        $client = (new BuildHttpClientFromEnv())($env);

        self::assertInstanceOf(HttpClient::class, $client);
        self::assertFalse($this->verifySslOf($client));
    }

    public function testNumericOneAndZeroAreReadAsBooleans(): void
    {
        // HTTP_VERIFY_SSL=0 in BoolCasting/OneZero/.env — the DotEnv cast
        // chain reads a bare "0"/"1" as int (CastStringToNumeric runs before
        // CastStringToBool), NormalizeEnvBool must still resolve it.
        $env = (new LoadEnvGetFromRealCascade())($this->fixturePath('BoolCasting/OneZero'));
        $client = (new BuildHttpClientFromEnv())($env);

        self::assertInstanceOf(HttpClient::class, $client);
        self::assertFalse($this->verifySslOf($client));
    }

    public function testDefaultsToVerifyOnWhenUnset(): void
    {
        $env = (new LoadEnvGetFromRealCascade())($this->fixturePath('ErrorRules/UnknownForeignKey'));
        $client = (new BuildHttpClientFromEnv())($env);

        self::assertInstanceOf(HttpClient::class, $client);
        self::assertTrue($this->verifySslOf($client));
    }

    public function testUnparsableValueThrowsInvalidEnvConfigurationException(): void
    {
        $env = (new LoadEnvGetFromRealCascade())($this->fixturePath('ErrorRules/InvalidHttpVerifySsl'));

        $this->expectException(InvalidEnvConfigurationException::class);

        (new BuildHttpClientFromEnv())($env);
    }
}
