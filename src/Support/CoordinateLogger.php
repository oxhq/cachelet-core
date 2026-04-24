<?php

namespace Oxhq\Cachelet\Support;

use Closure;
use Illuminate\Cache\TaggableStore;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Oxhq\Cachelet\ValueObjects\CacheCoordinate;
use Oxhq\Cachelet\ValueObjects\CacheScope;

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

    /**
     * @return array<int, CacheCoordinate>
     */
    public function coordinatesForScope(CacheScope|string $scope): array
    {
        $identifier = $scope instanceof CacheScope ? $scope->identifier : (string) $scope;
        $coordinates = [];

        foreach ($this->knownPrefixes() as $prefix) {
            foreach ($this->coordinatesForPrefix($prefix) as $coordinate) {
                if ($coordinate->scope?->identifier !== $identifier) {
                    continue;
                }

                $coordinates[] = $coordinate;
            }
        }

        return $coordinates;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forgetScope(CacheScope|string $scope): array
    {
        $deleted = [];

        foreach ($this->coordinatesForScope($scope) as $coordinate) {
            $this->forget($coordinate);
            $deleted[] = $coordinate->toProjection();
        }

        return $deleted;
    }

    protected function keysForPrefix(string $prefix): array
    {
        $keys = Cache::get($this->registryKey($prefix), []);

        if (! is_array($keys)) {
            return [];
        }

        return array_values(array_unique(array_filter($keys, 'is_string')));
    }

    /**
     * @return array<int, string>
     */
    protected function knownPrefixes(): array
    {
        $prefixes = Cache::get('cachelet:registry:prefixes', []);

        if (! is_array($prefixes)) {
            return [];
        }

        return array_values(array_unique(array_filter($prefixes, 'is_string')));
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
        $this->rememberPrefix($coordinate->prefix);

        if ($coordinate->ttl === null) {
            Cache::forever($this->metadataKey($coordinate->key), $payload);

            return;
        }

        Cache::put($this->metadataKey($coordinate->key), $payload, $coordinate->ttl);
    }

    protected function forgetStoredValue(CacheCoordinate $coordinate): void
    {
        $store = $this->repositoryForCoordinate($coordinate);

        if ($coordinate->tags !== [] && $this->supportsTags($store)) {
            $store->tags($coordinate->tags)->forget($coordinate->key);

            return;
        }

        $store->forget($coordinate->key);
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
            $this->forgetKnownPrefix($prefix);

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

    protected function repositoryForCoordinate(CacheCoordinate $coordinate): Repository
    {
        if ($coordinate->store) {
            try {
                return Cache::store($coordinate->store);
            } catch (\Throwable) {
                // Fall back to the default repository when the recorded store no longer exists.
            }
        }

        return Cache::store();
    }

    protected function rememberPrefix(string $prefix): void
    {
        $prefixes = $this->knownPrefixes();

        if (in_array($prefix, $prefixes, true)) {
            return;
        }

        $prefixes[] = $prefix;

        Cache::forever('cachelet:registry:prefixes', array_values($prefixes));
    }

    protected function forgetKnownPrefix(string $prefix): void
    {
        $prefixes = array_values(array_filter(
            $this->knownPrefixes(),
            static fn (string $knownPrefix): bool => $knownPrefix !== $prefix
        ));

        if ($prefixes === []) {
            Cache::forget('cachelet:registry:prefixes');

            return;
        }

        Cache::forever('cachelet:registry:prefixes', $prefixes);
    }
}
