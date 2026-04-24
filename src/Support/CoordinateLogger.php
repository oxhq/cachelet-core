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
    public function __construct(
        protected ?string $store = null,
        protected string $prefix = 'cachelet:registry',
        protected ?int $metadataTtl = null,
        protected int $lockTtl = 10,
        protected int $lockWait = 5,
    ) {}

    public function record(CacheCoordinate $coordinate): void
    {
        $this->withRegistryLock(function () use ($coordinate): void {
            $keys = $this->keysForPrefix($coordinate->prefix);
            $scopeKeys = $this->keysForScope($coordinate->scope);

            if (! in_array($coordinate->key, $keys, true)) {
                $keys[] = $coordinate->key;
                $this->sidecarStore()->forever($this->registryKey($coordinate->prefix), array_values($keys));
            }

            if (! in_array($coordinate->key, $scopeKeys, true)) {
                $scopeKeys[] = $coordinate->key;
                $this->sidecarStore()->forever($this->scopeRegistryKey($coordinate->scope), array_values($scopeKeys));
                $this->rememberScope($coordinate->scope);
            }

            $this->storeMetadata($coordinate);
        });
    }

    public function forget(CacheCoordinate $coordinate): void
    {
        $this->withRegistryLock(function () use ($coordinate): void {
            $this->forgetStoredValue($coordinate);
            $this->sidecarStore()->forget($this->metadataKey($coordinate->key));
            $this->removeFromRegistry($coordinate->prefix, $coordinate->key);
            $this->removeFromScopeRegistry($coordinate->scope, $coordinate->key);
        });
    }

    public function flush(string $prefix): array
    {
        return $this->withRegistryLock(function () use ($prefix): array {
            $coordinates = $this->coordinatesForPrefix($prefix);
            $deleted = [];

            foreach ($coordinates as $coordinate) {
                $this->forgetStoredValue($coordinate);
                $this->sidecarStore()->forget($this->metadataKey($coordinate->key));
                $this->removeFromScopeRegistry($coordinate->scope, $coordinate->key);
                $deleted[] = $coordinate->key;
            }

            $this->sidecarStore()->forget($this->registryKey($prefix));
            $this->forgetKnownPrefix($prefix);

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
        $coordinates = [];

        foreach ($this->keysForScope($scope) as $key) {
            $metadata = $this->sidecarStore()->get($this->metadataKey($key));

            if (! is_array($metadata) || ! isset($metadata['key'])) {
                continue;
            }

            $coordinates[] = CacheCoordinate::fromArray($metadata);
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

    /**
     * @return array<string, int>
     */
    public function prune(): array
    {
        return $this->withRegistryLock(function (): array {
            $removedCoordinates = 0;
            $removedPrefixes = 0;
            $removedScopes = 0;
            $scannedPrefixes = 0;
            $scannedScopes = 0;
            $scopeRegistry = [];

            foreach ($this->knownPrefixes() as $prefix) {
                $scannedPrefixes++;
                $validKeys = [];

                foreach ($this->keysForPrefix($prefix) as $key) {
                    $coordinate = $this->coordinateForKey($key);

                    if (! $coordinate instanceof CacheCoordinate || ! $this->storedValueExists($coordinate)) {
                        $this->sidecarStore()->forget($this->metadataKey($key));
                        $removedCoordinates++;

                        continue;
                    }

                    $validKeys[] = $coordinate->key;

                    if ($coordinate->scope instanceof CacheScope) {
                        $scopeRegistry[$coordinate->scope->identifier] ??= [
                            'scope' => $coordinate->scope,
                            'keys' => [],
                        ];

                        $scopeRegistry[$coordinate->scope->identifier]['keys'][] = $coordinate->key;
                    }
                }

                if ($validKeys === []) {
                    $this->sidecarStore()->forget($this->registryKey($prefix));
                    $this->forgetKnownPrefix($prefix);
                    $removedPrefixes++;

                    continue;
                }

                $this->sidecarStore()->forever($this->registryKey($prefix), array_values(array_unique($validKeys)));
            }

            foreach ($this->knownScopes() as $identifier) {
                $scannedScopes++;

                if (! isset($scopeRegistry[$identifier])) {
                    $this->sidecarStore()->forget($this->scopeRegistryKey($identifier));
                    $this->forgetKnownScope($identifier);
                    $removedScopes++;

                    continue;
                }

                $scope = $scopeRegistry[$identifier]['scope'];
                $keys = array_values(array_unique($scopeRegistry[$identifier]['keys']));

                if ($keys === []) {
                    $this->sidecarStore()->forget($this->scopeRegistryKey($scope));
                    $this->forgetKnownScope($scope);
                    $removedScopes++;

                    continue;
                }

                $this->sidecarStore()->forever($this->scopeRegistryKey($scope), $keys);
            }

            foreach ($scopeRegistry as $entry) {
                $this->rememberScope($entry['scope']);
            }

            return [
                'scanned_prefixes' => $scannedPrefixes,
                'scanned_scopes' => $scannedScopes,
                'removed_coordinates' => $removedCoordinates,
                'removed_prefixes' => $removedPrefixes,
                'removed_scopes' => $removedScopes,
            ];
        });
    }

    protected function keysForPrefix(string $prefix): array
    {
        $keys = $this->sidecarStore()->get($this->registryKey($prefix), []);

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
        $prefixes = $this->sidecarStore()->get($this->knownPrefixesKey(), []);

        if (! is_array($prefixes)) {
            return [];
        }

        return array_values(array_unique(array_filter($prefixes, 'is_string')));
    }

    protected function coordinatesForPrefix(string $prefix): array
    {
        $coordinates = [];

        foreach ($this->keysForPrefix($prefix) as $key) {
            $coordinate = $this->coordinateForKey($key);

            if ($coordinate instanceof CacheCoordinate) {
                $coordinates[] = $coordinate;
            }
        }

        return $coordinates;
    }

    protected function storeMetadata(CacheCoordinate $coordinate): void
    {
        $payload = $coordinate->toArray();
        $this->rememberPrefix($coordinate->prefix);

        $ttl = $this->metadataLifetime($coordinate);

        if ($ttl === null) {
            $this->sidecarStore()->forever($this->metadataKey($coordinate->key), $payload);

            return;
        }

        $this->sidecarStore()->put($this->metadataKey($coordinate->key), $payload, $ttl);
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
            $this->sidecarStore()->forget($this->registryKey($prefix));
            $this->forgetKnownPrefix($prefix);

            return;
        }

        $this->sidecarStore()->forever($this->registryKey($prefix), $remaining);
    }

    protected function removeFromScopeRegistry(?CacheScope $scope, string $key): void
    {
        $remaining = array_values(array_filter(
            $this->keysForScope($scope),
            static fn (string $registeredKey): bool => $registeredKey !== $key
        ));

        if ($remaining === []) {
            $this->sidecarStore()->forget($this->scopeRegistryKey($scope));
            $this->forgetKnownScope($scope);

            return;
        }

        $this->sidecarStore()->forever($this->scopeRegistryKey($scope), $remaining);
    }

    /**
     * @return array<int, string>
     */
    protected function keysForScope(CacheScope|string|null $scope): array
    {
        $keys = $this->sidecarStore()->get($this->scopeRegistryKey($scope), []);

        if (! is_array($keys)) {
            return [];
        }

        return array_values(array_unique(array_filter($keys, 'is_string')));
    }

    protected function withRegistryLock(Closure $callback): mixed
    {
        try {
            $cache = Cache::getFacadeRoot();

            if ($cache === null) {
                return $callback();
            }

            if (is_string($this->store) && $this->store !== '') {
                return $cache->store($this->store)->lock($this->registryLockKey(), $this->lockTtl)->block($this->lockWait, $callback);
            }

            return $cache->lock($this->registryLockKey(), $this->lockTtl)->block($this->lockWait, $callback);
        } catch (\Throwable) {
            return $callback();
        }
    }

    protected function registryKey(string $prefix): string
    {
        return $this->prefix.':prefix:'.md5($prefix);
    }

    protected function scopeRegistryKey(CacheScope|string|null $scope): string
    {
        $identifier = $scope instanceof CacheScope ? $scope->identifier : (string) $scope;

        return $this->prefix.':scope:'.sha1($identifier);
    }

    protected function registryLockKey(): string
    {
        return $this->prefix.':lock';
    }

    protected function metadataKey(string $key): string
    {
        return $this->prefix.':meta:'.sha1($key);
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

        $this->sidecarStore()->forever($this->knownPrefixesKey(), array_values($prefixes));
    }

    protected function rememberScope(CacheScope|string|null $scope): void
    {
        $identifier = $this->scopeIdentifier($scope);

        if ($identifier === null) {
            return;
        }

        $scopes = $this->knownScopes();

        if (in_array($identifier, $scopes, true)) {
            return;
        }

        $scopes[] = $identifier;

        $this->sidecarStore()->forever($this->knownScopesKey(), array_values($scopes));
    }

    protected function forgetKnownPrefix(string $prefix): void
    {
        $prefixes = array_values(array_filter(
            $this->knownPrefixes(),
            static fn (string $knownPrefix): bool => $knownPrefix !== $prefix
        ));

        if ($prefixes === []) {
            $this->sidecarStore()->forget($this->knownPrefixesKey());

            return;
        }

        $this->sidecarStore()->forever($this->knownPrefixesKey(), $prefixes);
    }

    protected function forgetKnownScope(CacheScope|string|null $scope): void
    {
        $identifier = $this->scopeIdentifier($scope);

        if ($identifier === null) {
            return;
        }

        $scopes = array_values(array_filter(
            $this->knownScopes(),
            static fn (string $knownScope): bool => $knownScope !== $identifier
        ));

        if ($scopes === []) {
            $this->sidecarStore()->forget($this->knownScopesKey());

            return;
        }

        $this->sidecarStore()->forever($this->knownScopesKey(), $scopes);
    }

    protected function metadataLifetime(CacheCoordinate $coordinate): ?int
    {
        if ($coordinate->ttl === null) {
            return $this->metadataTtl;
        }

        $lifetime = $coordinate->ttl + max(0, (int) ($coordinate->swr['grace_ttl'] ?? 0));

        if ($this->metadataTtl === null) {
            return $lifetime;
        }

        return max($lifetime, $this->metadataTtl);
    }

    protected function knownPrefixesKey(): string
    {
        return $this->prefix.':prefixes';
    }

    protected function knownScopesKey(): string
    {
        return $this->prefix.':scopes';
    }

    /**
     * @return array<int, string>
     */
    protected function knownScopes(): array
    {
        $scopes = $this->sidecarStore()->get($this->knownScopesKey(), []);

        if (! is_array($scopes)) {
            return [];
        }

        return array_values(array_unique(array_filter($scopes, 'is_string')));
    }

    protected function scopeIdentifier(CacheScope|string|null $scope): ?string
    {
        $identifier = $scope instanceof CacheScope ? $scope->identifier : trim((string) $scope);

        return $identifier === '' ? null : $identifier;
    }

    protected function coordinateForKey(string $key): ?CacheCoordinate
    {
        $metadata = $this->sidecarStore()->get($this->metadataKey($key));

        if (! is_array($metadata) || ! isset($metadata['key'])) {
            return null;
        }

        return CacheCoordinate::fromArray($metadata);
    }

    protected function storedValueExists(CacheCoordinate $coordinate): bool
    {
        try {
            $store = $this->repositoryForCoordinate($coordinate);

            if ($coordinate->tags !== [] && $this->supportsTags($store)) {
                return $store->tags($coordinate->tags)->has($coordinate->key);
            }

            return $store->has($coordinate->key);
        } catch (\Throwable) {
            return false;
        }
    }

    protected function sidecarStore(): Repository
    {
        if (is_string($this->store) && $this->store !== '') {
            try {
                return Cache::store($this->store);
            } catch (\Throwable) {
                // Fall back to the default repository when the configured sidecar store is unavailable.
            }
        }

        return Cache::store();
    }
}
