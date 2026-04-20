<?php

namespace Oxhq\Cachelet\Concerns;

use Closure;
use Illuminate\Cache\TaggableStore;
use Illuminate\Cache\TaggedCache;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Oxhq\Cachelet\Events\CacheletHit;
use Oxhq\Cachelet\Events\CacheletInvalidated;
use Oxhq\Cachelet\Events\CacheletMiss;
use Oxhq\Cachelet\Events\CacheletStored;
use Oxhq\Cachelet\Support\CoordinateLogger;
use stdClass;

trait BuildsCache
{
    public function fetch(?Closure $callback = null): mixed
    {
        $entry = $this->getStoredEntry();

        if ($entry !== $this->missingValueSentinel()) {
            $this->dispatchCacheEvent('hit', $this->key(), $entry['value']);

            return $entry['value'];
        }

        $this->dispatchCacheEvent('miss', $this->key());

        if ($callback === null) {
            return null;
        }

        return $this->computeAndStoreWithLock($callback);
    }

    public function staleWhileRevalidate(Closure $callback, ?Closure $fallback = null): mixed
    {
        $entry = $this->getStoredEntry();

        if ($entry !== $this->missingValueSentinel()) {
            $this->dispatchCacheEvent('hit', $this->key(), $entry['value']);

            if ($this->isStale($entry)) {
                $this->backgroundRefresh($callback);
            }

            return $entry['value'];
        }

        $this->dispatchCacheEvent('miss', $this->key());

        if ($fallback !== null) {
            $this->backgroundRefresh($callback);

            return value($fallback);
        }

        return $this->computeAndStoreWithLock($callback, stale: true);
    }

    protected function backgroundRefresh(Closure $callback): void
    {
        $lockKey = $this->key().($this->config['stale']['lock_suffix'] ?? ':refresh');
        $lockTtl = (int) ($this->config['stale']['lock_ttl'] ?? 30);

        if (! Cache::add($lockKey, true, $lockTtl)) {
            return;
        }

        $refresh = function () use ($callback, $lockKey): void {
            try {
                $this->computeAndStoreWithLock($callback, stale: true);
            } finally {
                Cache::forget($lockKey);
            }
        };

        match ($this->config['stale']['refresh'] ?? 'sync') {
            'sync' => $refresh(),
            'defer' => app()->terminating($refresh),
            'queue' => $this->dispatchQueuedRefresh($refresh),
            default => throw new InvalidArgumentException('Invalid stale refresh mode configured.'),
        };
    }

    protected function computeAndStoreWithLock(Closure $callback, bool $stale = false): mixed
    {
        $lock = $this->computationLock();

        if ($lock === null) {
            return $this->computeAndStore($callback, $stale);
        }

        try {
            return $lock->block($this->fillLockWaitSeconds(), function () use ($callback, $stale): mixed {
                $current = $this->getStoredEntry();

                if (
                    $current !== $this->missingValueSentinel()
                    && (! $stale || ! $this->isStale($current))
                ) {
                    return $current['value'];
                }

                return $this->computeAndStore($callback, $stale);
            });
        } catch (LockTimeoutException) {
            $current = $this->getStoredEntry();

            if ($current !== $this->missingValueSentinel()) {
                return $current['value'];
            }

            return $this->computeAndStore($callback, $stale);
        } catch (\Throwable) {
            return $this->computeAndStore($callback, $stale);
        }
    }

    protected function computeAndStore(Closure $callback, bool $stale = false): mixed
    {
        $value = value($callback);

        $this->putStoredValue($value, $stale);
        $this->coordinateLogger()->record($this->coordinate());
        $this->dispatchCacheEvent('stored', $this->key(), $value);

        return $value;
    }

    protected function getStoredEntry(): array|stdClass
    {
        $payload = $this->resolveStore()->get($this->key());

        if (! is_array($payload) || ! array_key_exists('value', $payload)) {
            return $this->missingValueSentinel();
        }

        return $payload;
    }

    protected function putStoredValue(mixed $value, bool $stale = false): void
    {
        $store = $this->resolveStore();
        $entry = $this->makeStoredEntry($value, $stale);
        $ttl = $this->ttlForEntry($entry);

        if ($ttl === null) {
            $store->forever($this->key(), $entry);

            return;
        }

        $store->put($this->key(), $entry, $ttl);
    }

    protected function makeStoredEntry(mixed $value, bool $stale): array
    {
        $freshTtl = $this->duration();

        if ($freshTtl === null) {
            return [
                'value' => $value,
                'fresh_until' => null,
                'stale_until' => null,
            ];
        }

        $now = Carbon::now();
        $freshUntil = $now->copy()->addSeconds($freshTtl);
        $staleUntil = $freshUntil->copy();

        if ($stale) {
            $graceTtl = max(1, (int) ($this->config['stale']['grace_ttl'] ?? $freshTtl));
            $staleUntil = $freshUntil->copy()->addSeconds($graceTtl);
        }

        return [
            'value' => $value,
            'fresh_until' => $freshUntil->toIso8601String(),
            'stale_until' => $staleUntil->toIso8601String(),
        ];
    }

    protected function ttlForEntry(array $entry): ?int
    {
        if ($entry['stale_until'] === null) {
            return null;
        }

        return max(
            1,
            Carbon::now()->diffInSeconds(Carbon::parse($entry['stale_until']), false)
        );
    }

    protected function isStale(array $entry): bool
    {
        if ($entry['fresh_until'] === null) {
            return false;
        }

        return Carbon::now()->greaterThan(Carbon::parse($entry['fresh_until']));
    }

    protected function resolveStore(): Repository|TaggedCache
    {
        $store = Cache::store();

        if ($this->tags === [] || ! $this->supportsTags($store)) {
            return $store;
        }

        return $store->tags($this->tags);
    }

    protected function supportsTags(Repository $store): bool
    {
        return $store->getStore() instanceof TaggableStore;
    }

    protected function dispatchCacheEvent(string $type, string $key, mixed $value = null): void
    {
        if (! ($this->config['observability']['events']['enabled'] ?? false)) {
            return;
        }

        $event = match ($type) {
            'hit' => new CacheletHit($key, $value),
            'miss' => new CacheletMiss($key),
            'stored' => new CacheletStored($key, $value),
            default => throw new InvalidArgumentException("Unknown cache event type [{$type}]."),
        };

        event($event);
    }

    protected function dispatchInvalidatedEvent(array $keys, string $reason = 'manual'): void
    {
        if (! ($this->config['observability']['events']['enabled'] ?? false)) {
            return;
        }

        event($this->makeInvalidatedEvent($keys, $reason));
    }

    protected function makeInvalidatedEvent(array $keys, string $reason): CacheletInvalidated
    {
        return new CacheletInvalidated(
            prefix: $this->prefix,
            keys: array_values($keys),
            reason: $reason
        );
    }

    protected function coordinateLogger(): CoordinateLogger
    {
        return app(CoordinateLogger::class);
    }

    protected function dispatchQueuedRefresh(Closure $refresh): void
    {
        if (app()->runningInConsole()) {
            $refresh();

            return;
        }

        try {
            dispatch($refresh)->afterResponse();
        } catch (\Throwable) {
            $refresh();
        }
    }

    protected function computationLock(): mixed
    {
        try {
            return Cache::lock(
                $this->key().($this->config['locks']['fill_suffix'] ?? ':fill'),
                $this->fillLockTtlSeconds()
            );
        } catch (\Throwable) {
            return null;
        }
    }

    protected function fillLockTtlSeconds(): int
    {
        return max(1, (int) ($this->config['locks']['fill_ttl'] ?? 30));
    }

    protected function fillLockWaitSeconds(): int
    {
        return max(1, (int) ($this->config['locks']['fill_wait'] ?? 5));
    }

    protected function missingValueSentinel(): stdClass
    {
        static $sentinel;

        if (! $sentinel instanceof stdClass) {
            $sentinel = new stdClass;
        }

        return $sentinel;
    }
}
