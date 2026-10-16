<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Bootstrap\Handler;

use Closure;
use JardisAdapter\DbConnection\Factory\ConnectionFactory;
use JardisCore\Kernel\Bootstrap\LazyAuthServices;
use JardisCore\Kernel\Exception\InvalidEnvConfigurationException;
use JardisSupport\Auth\AuthServices;
use JardisSupport\Auth\DbTokenStore;
use JardisSupport\Contract\Auth\AuthServicesInterface;
use JardisSupport\Contract\DbConnection\ConnectionPoolInterface;
use JardisSupport\Contract\DbConnection\DbConnectionInterface;
use PDO;

/**
 * Builds the auth services from ENV values.
 *
 * Requires jardissupport/auth. The signing key comes from `AUTH_TOKEN_KEY`
 * (base64 of 32 raw bytes — the same decode the generated middleware applies);
 * without it there is no auth and the result is `null`. The refresh-token
 * store is `DbTokenStore` on the SAME connection the kernel hands to the
 * domain: a pool contributes its writer, a bare PDO (only a hand-built
 * connection — the packer always yields a pool) is wrapped via `ConnectionFactory::fromPdo(manageLifecycle: false)`
 * from jardisadapter/dbconnection — the same bridge the messaging transport
 * uses. No adapter code of its own lives here.
 *
 * The result is a {@see LazyAuthServices}: resolving the writer may open the
 * connection, so the store is only built on first use. Everything that can be
 * checked without touching the database (key format, a configured database,
 * the adapter) is checked eagerly and throws
 * {@see InvalidEnvConfigurationException} naming the ENV key — a configured
 * key never degrades silently.
 */
final class BuildAuthServicesFromEnv
{
    /** @param (Closure(): bool)|null $authAvailable replaces the `class_exists(AuthServices::class)` check (test seam) */
    public function __construct(private readonly ?Closure $authAvailable = null)
    {
    }

    /** @param Closure(string): mixed $env */
    public function __invoke(Closure $env, ConnectionPoolInterface|PDO|null $db): ?AuthServicesInterface
    {
        if (!$this->authInstalled()) {
            return null;
        }

        $encoded = $env('auth_token_key');
        if ((new IsEnvValueUnset())($encoded)) {
            return null;
        }

        $key = base64_decode((string) $encoded, true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_AUTH_KEYBYTES) {
            throw new InvalidEnvConfigurationException(
                sprintf('AUTH_TOKEN_KEY must be base64 of exactly %d bytes.', SODIUM_CRYPTO_AUTH_KEYBYTES)
            );
        }

        if ($db === null) {
            throw new InvalidEnvConfigurationException(
                'AUTH_TOKEN_KEY requires a configured database (DB_*): the auth token store lives there.'
            );
        }

        if ($db instanceof PDO && !class_exists(ConnectionFactory::class)) {
            throw new InvalidEnvConfigurationException(
                'AUTH_TOKEN_KEY on a plain PDO connection requires jardisadapter/dbconnection.'
            );
        }

        return new LazyAuthServices(
            static fn (): AuthServicesInterface => AuthServices::fromStore(
                new DbTokenStore(self::connection($db)),
                $key,
            )
        );
    }

    private function authInstalled(): bool
    {
        return $this->authAvailable !== null
            ? ($this->authAvailable)()
            : class_exists(AuthServices::class);
    }

    private static function connection(ConnectionPoolInterface|PDO $db): DbConnectionInterface
    {
        return $db instanceof ConnectionPoolInterface
            ? $db->getWriter()
            : (new ConnectionFactory())->fromPdo($db, manageLifecycle: false);
    }
}
