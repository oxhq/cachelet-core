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
use Oxhq\Cachelet\Support\CacheTelemetryEmitter;
use Oxhq\Cachelet\Support\CoordinateLogger;
use stdClass;

trait BuildsCache
{
    public function fetch(?Closure $callback = null): mixed
    {
        $entry = $this->getStoredEntry();

        if ($entry !== $this->missingValueSentinel()) {
            $this->dispatchCacheEvent('hit', $this->key(), $entry['value'], [
                'access_strategy' => 'standard',
                'entry_state' => 'fresh',
            ]);

            return $entry['value'];
        }

        $this->dispatchCacheEvent('miss', $this->key(), null, [
            'access_strategy' => 'standard',
            'entry_state' => 'missing',
        ]);

        if ($callback === null) {
            return null;
        }

        return $this->computeAndStoreWithLock($callback, false, [
            'access_strategy' => 'standard',
            'entry_state' => 'missing',
        ]);
    }

    public function staleWhileRevalidate(Closure $callback, ?Closure $fallback = null): mixed
    {
        $entry = $this->getStoredEntry();

        if ($entry !== $this->missingValueSentinel()) {
            $entryState = $this->isStale($entry) ? 'stale' : 'fresh';
            $this->dispatchCacheEvent('hit', $this->key(), $entry['value'], [
                'access_strategy' => 'stale_while_revalidate',
                'entry_state' => $entryState,
            ]);

            if ($entryState === 'stale') {
                $this->backgroundRefresh($callback);
            }

            return $entry['value'];
        }

        $this->dispatchCacheEvent('miss', $this->key(), null, [
            'access_strategy' => 'stale_while_revalidate',
            'entry_state' => 'missing',
        ]);

        if ($fallback !== null) {
            $this->backgroundRefresh($callback);

            return value($fallback);
        }

        return $this->computeAndStoreWithLock($callback, stale: true, context: [
            'access_strategy' => 'stale_while_revalidate',
            'entry_state' => 'missing',
        ]);
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
                $this->computeAndStoreWithLock($callback, stale: true, context: [
                    'access_strategy' => 'stale_while_revalidate',
                    'background' => true,
                    'entry_state' => 'stale',
                ]);
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

    protected function computeAndStoreWithLock(Closure $callback, bool $stale = false, array $context = []): mixed
    {
        $lock = $this->computationLock();

        if ($lock === null) {
            return $this->computeAndStore($callback, $stale, $context);
        }

        try {
            return $lock->block($this->fillLockWaitSeconds(), function () use ($callback, $stale, $context): mixed {
                $current = $this->getStoredEntry();

                if (
                    $current !== $this->missingValueSentinel()
                    && (! $stale || ! $this->isStale($current))
                ) {
                    return $current['value'];
                }

                return $this->computeAndStore($callback, $stale, $context);
            });
        } catch (LockTimeoutException) {
            $current = $this->getStoredEntry();

            if ($current !== $this->missingValueSentinel()) {
                return $current['value'];
            }

            return $this->computeAndStore($callback, $stale, $context);
        } catch (\Throwable) {
            return $this->computeAndStore($callback, $stale, $context);
        }
    }

    protected function computeAndStore(Closure $callback, bool $stale = false, array $context = []): mixed
    {
        $value = value($callback);

        $this->putStoredValue($value, $stale);
        $this->coordinateLogger()->record($this->coordinate());
        $this->dispatchCacheEvent('stored', $this->key(), $value, array_merge($context, [
            'value_type' => get_debug_type($value),
            'stale_window' => $stale,
        ]));

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
        $store = $this->resolveRepository();

        if ($this->tags === [] || ! $this->supportsTags($store)) {
            return $store;
        }

        return $store->tags($this->tags);
    }

    protected function resolveRepository(): Repository
    {
        if (is_string($this->store) && $this->store !== '') {
            return Cache::store($this->store);
        }

        return Cache::store();
    }

    protected function resolvedStoreName(): string
    {
        if (is_string($this->store) && $this->store !== '') {
            return $this->store;
        }

        $repository = $this->resolveRepository();
        $stores = array_keys(config('cache.stores', []));

        foreach ($stores as $name) {
            try {
                $candidate = Cache::store($name);
            } catch (\Throwable) {
                continue;
            }

            if (
                $candidate === $repository
                || $candidate->getStore() === $repository->getStore()
            ) {
                return (string) $name;
            }
        }

        return (string) (config('cache.default') ?? Cache::getDefaultDriver());
    }

    protected function supportsTags(Repository $store): bool
    {
        return $store->getStore() instanceof TaggableStore;
    }

    protected function dispatchCacheEvent(string $type, string $key, mixed $value = null, array $context = []): void
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
        $this->telemetryEmitter()->emit($type, $this->coordinate(), $this->withSwrRuntime($context));
    }

    protected function dispatchInvalidatedEvent(array $keys, string $reason = 'manual'): void
    {
        if (! ($this->config['observability']['events']['enabled'] ?? false)) {
            return;
        }

        $event = $this->makeInvalidatedEvent($keys, $reason);

        event($event);
        $this->telemetryEmitter()->emit('invalidated', $this->coordinate(), array_filter([
            'reason' => $reason,
            'keys' => array_values($keys),
            'model_class' => $event->modelClass,
            'model_key' => $event->modelKey,
        ], static fn (mixed $value): bool => $value !== null));
    }

    protected function withSwrRuntime(array $context): array
    {
        if (! array_key_exists('access_strategy', $context)) {
            return $context;
        }

        $entryState = (string) ($context['entry_state'] ?? 'missing');
        $requested = ($context['access_strategy'] ?? 'standard') === 'stale_while_revalidate';
        $backgroundRefresh = (bool) ($context['background'] ?? false);

        $context['swr_runtime'] = [
            'requested' => $requested,
            'background_refresh' => $backgroundRefresh,
            'served_stale' => $requested && $entryState === 'stale' && ! $backgroundRefresh,
            'entry_state' => $entryState,
        ];

        return $context;
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

    protected function telemetryEmitter(): CacheTelemetryEmitter
    {
        return app(CacheTelemetryEmitter::class);
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
