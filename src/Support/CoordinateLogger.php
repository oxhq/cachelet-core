<?php

namespace Oxhq\Cachelet\Support;

use Closure;
use Illuminate\Cache\TaggableStore;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Oxhq\Cachelet\ValueObjects\CacheCoordinate;

class CoordinateLogger
{
    public function record(CacheCoordinate $coordinate): void
    {
        $this->withRegistryLock($coordinate->prefix, function () use ($coordinate): void {
            $keys = $this->keysForPrefix($coordinate->prefix);

            if (! in_array($coordinate->key, $keys, true)) {
                $keys[] = $coordinate->key;
                Cache::forever($this->registryKey($coordinate->prefix), array_values($keys));
            }

            $this->storeMetadata($coordinate);
        });
    }

    public function forget(CacheCoordinate $coordinate): void
    {
        $this->withRegistryLock($coordinate->prefix, function () use ($coordinate): void {
            $this->forgetStoredValue($coordinate);
            Cache::forget($this->metadataKey($coordinate->key));
            $this->removeFromRegistry($coordinate->prefix, $coordinate->key);
        });
    }

    public function flush(string $prefix): array
    {
        return $this->withRegistryLock($prefix, function () use ($prefix): array {
            $coordinates = $this->coordinatesForPrefix($prefix);
            $deleted = [];

            foreach ($coordinates as $coordinate) {
                $this->forgetStoredValue($coordinate);
                Cache::forget($this->metadataKey($coordinate->key));
                $deleted[] = $coordinate->key;
            }

            Cache::forget($this->registryKey($prefix));

            return $deleted;
        });
    }

    public function inspect(string $prefix): array
    {
        return array_map(
            static fn (CacheCoordinate $coordinate): array => $coordinate->toArray(),
            $this->coordinatesForPrefix($prefix)
        );
    }

    public function keys(string $prefix): array
    {
        return $this->keysForPrefix($prefix);
    }

    protected function keysForPrefix(string $prefix): array
    {
        $keys = Cache::get($this->registryKey($prefix), []);

        if (! is_array($keys)) {
            return [];
        }

        return array_values(array_unique(array_filter($keys, 'is_string')));
    }

    protected function coordinatesForPrefix(string $prefix): array
    {
        $coordinates = [];

        foreach ($this->keysForPrefix($prefix) as $key) {
            $metadata = Cache::get($this->metadataKey($key));

            if (! is_array($metadata) || ! isset($metadata['key'])) {
                continue;
            }

            $coordinates[] = CacheCoordinate::fromArray($metadata);
        }

        return $coordinates;
    }

    protected function storeMetadata(CacheCoordinate $coordinate): void
    {
        $payload = $coordinate->toArray();

        if ($coordinate->ttl === null) {
            Cache::forever($this->metadataKey($coordinate->key), $payload);

            return;
        }

        Cache::put($this->metadataKey($coordinate->key), $payload, $coordinate->ttl);
    }

    protected function forgetStoredValue(CacheCoordinate $coordinate): void
    {
        $store = Cache::store();

        if ($coordinate->tags !== [] && $this->supportsTags($store)) {
            $store->tags($coordinate->tags)->forget($coordinate->key);

            return;
        }

        Cache::forget($coordinate->key);
    }

    protected function supportsTags(Repository $store): bool
    {
        return $store->getStore() instanceof TaggableStore;
    }

    protected function removeFromRegistry(string $prefix, string $key): void
    {
        $remaining = array_values(array_filter(
            $this->keysForPrefix($prefix),
            static fn (string $registeredKey): bool => $registeredKey !== $key
        ));

        if ($remaining === []) {
            Cache::forget($this->registryKey($prefix));

            return;
        }

        Cache::forever($this->registryKey($prefix), $remaining);
    }

    protected function withRegistryLock(string $prefix, Closure $callback): mixed
    {
        try {
            return Cache::lock($this->registryLockKey($prefix), 10)->block(5, $callback);
        } catch (\Throwable) {
            return $callback();
        }
    }

    protected function registryKey(string $prefix): string
    {
        return 'cachelet:registry:'.md5($prefix);
    }

    protected function registryLockKey(string $prefix): string
    {
        return 'cachelet:registry-lock:'.md5($prefix);
    }

    protected function metadataKey(string $key): string
    {
        return 'cachelet:meta:'.sha1($key);
    }
}
