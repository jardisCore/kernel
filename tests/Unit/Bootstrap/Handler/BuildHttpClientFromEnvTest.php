<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Tests\Unit\Bootstrap\Handler;

use Closure;
use JardisCore\Kernel\Bootstrap\Handler\BuildHttpClientFromEnv;
use JardisCore\Kernel\Exception\InvalidEnvConfigurationException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;

/**
 * Tests for BuildHttpClientFromEnv — ported 1:1 from foundation's HttpClientHandler.
 *
 * Building the client performs no network I/O (lazy transport), so these
 * tests need no real HTTP endpoint. Values here are handed in already cast
 * (`bool`, not `'true'`/`'false'` strings) — that is what the real DotEnv
 * cascade actually produces (see the Integration suite,
 * `HttpVerifySslCascadeTest`, for the proof against a real fixture).
 */
final class BuildHttpClientFromEnvTest extends TestCase
{
    public function testBuildsClientWithDefaults(): void
    {
        $client = (new BuildHttpClientFromEnv())($this->envFrom([]));

        self::assertInstanceOf(ClientInterface::class, $client);
    }

    public function testBuildsClientWithFullEnvOverrides(): void
    {
        $client = (new BuildHttpClientFromEnv())($this->envFrom([
            'http_base_url' => 'https://api.example.com',
            'http_timeout' => '15',
            'http_connect_timeout' => '5',
            'http_verify_ssl' => false,
            'http_bearer_token' => 'secret-token',
            'http_max_retries' => '2',
            'http_retry_delay_ms' => '50',
        ]));

        self::assertInstanceOf(ClientInterface::class, $client);
    }

    public function testUnparsableVerifySslThrows(): void
    {
        $this->expectException(InvalidEnvConfigurationException::class);

        (new BuildHttpClientFromEnv())($this->envFrom(['http_verify_ssl' => 'maybe']));
    }

    public function testBuildsClientWithBasicAuthOverrides(): void
    {
        $client = (new BuildHttpClientFromEnv())($this->envFrom([
            'http_basic_user' => 'user',
            'http_basic_password' => 'pass',
        ]));

        self::assertInstanceOf(ClientInterface::class, $client);
    }

    /**
     * @param array<string, mixed> $data
     * @return Closure(string): mixed
     */
    private function envFrom(array $data): Closure
    {
        return static fn (string $key): mixed => $data[strtolower($key)] ?? null;
    }
}
