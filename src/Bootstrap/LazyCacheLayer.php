<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Bootstrap;

use Closure;
use Psr\SimpleCache\CacheInterface;

/**
 * Defers building one cache layer until it is first used (Lazy
 * Initialization, same idea as {@see LazyAuthServices}).
 *
 * The `redis` and `db` layers of `jardisadapter/cache` take an opened `Redis`
 * client / `PDO` in their constructor, so building them opens the resource.
 * The packer wraps them in this proxy: assembling the `Cache` touches neither
 * Redis nor the database, the first cache call on that layer does.
 */
final class LazyCacheLayer implements CacheInterface
{
    private ?CacheInterface $layer = null;

    /** @param Closure(): CacheInterface $build */
    public function __construct(private readonly Closure $build)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->layer()->get($key, $default);
    }

    public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        return $this->layer()->set($key, $value, $ttl);
    }

    public function delete(string $key): bool
    {
        return $this->layer()->delete($key);
    }

    public function clear(): bool
    {
        return $this->layer()->clear();
    }

    /** @param iterable<string> $keys */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        return $this->layer()->getMultiple($keys, $default);
    }

    /** @param iterable<string, mixed> $values */
    public function setMultiple(iterable $values, \DateInterval|int|null $ttl = null): bool
    {
        return $this->layer()->setMultiple($values, $ttl);
    }

    /** @param iterable<string> $keys */
    public function deleteMultiple(iterable $keys): bool
    {
        return $this->layer()->deleteMultiple($keys);
    }

    public function has(string $key): bool
    {
        return $this->layer()->has($key);
    }

    private function layer(): CacheInterface
    {
        return $this->layer ??= ($this->build)();
    }
}
