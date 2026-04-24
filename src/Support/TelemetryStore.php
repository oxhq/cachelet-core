<?php

namespace Oxhq\Cachelet\Support;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Oxhq\Cachelet\ValueObjects\CacheScope;
use Oxhq\Cachelet\ValueObjects\CacheTelemetryRecord;

class TelemetryStore
{
    public function __construct(
        protected ?string $store = null,
        protected string $prefix = 'cachelet:telemetry',
        protected int $perScopeLimit = 100,
        protected ?int $retention = 86400,
    ) {}

    public function record(CacheTelemetryRecord $record): void
    {
        $scope = $record->coordinate->scope;

        if (! $scope instanceof CacheScope) {
            return;
        }

        $key = $this->scopeKey($scope);
        $records = $this->store()->get($key, []);

        if (! is_array($records)) {
            $records = [];
        }

        $records[] = $record->toArray();
        $records = array_slice($records, -$this->perScopeLimit);
        $this->rememberScope($scope);

        if ($this->retention === null) {
            $this->store()->forever($key, $records);

            return;
        }

        $this->store()->put($key, $records, $this->retention);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recordsForScopeSince(CacheScope $scope, string $since): array
    {
        $records = $this->store()->get($this->scopeKey($scope), []);

        if (! is_array($records)) {
            return [];
        }

        $sinceAt = CarbonImmutable::parse($since);

        return array_values(array_filter($records, static function (mixed $record) use ($sinceAt): bool {
            if (! is_array($record) || ! isset($record['occurred_at'])) {
                return false;
            }

            return CarbonImmutable::parse((string) $record['occurred_at'])->greaterThanOrEqualTo($sinceAt);
        }));
    }

    /**
     * @return array<string, int>
     */
    public function prune(): array
    {
        $removedScopes = 0;
        $removedRecords = 0;
        $scannedScopes = 0;

        foreach ($this->knownScopes() as $identifier) {
            $scannedScopes++;
            $scope = CacheScope::named($identifier);
            $key = $this->scopeKey($scope);
            $records = $this->store()->get($key, []);

            if (! is_array($records)) {
                $this->store()->forget($key);
                $this->forgetScope($scope);
                $removedScopes++;

                continue;
            }

            $filtered = $this->prunableRecords($records);
            $removedRecords += max(0, count($records) - count($filtered));

            if ($filtered === []) {
                $this->store()->forget($key);
                $this->forgetScope($scope);
                $removedScopes++;

                continue;
            }

            if ($this->retention === null) {
                $this->store()->forever($key, $filtered);
            } else {
                $this->store()->put($key, $filtered, $this->retention);
            }
        }

        return [
            'scanned_scopes' => $scannedScopes,
            'removed_scopes' => $removedScopes,
            'removed_records' => $removedRecords,
        ];
    }

    protected function scopeKey(CacheScope $scope): string
    {
        return $this->prefix.':scope:'.sha1($scope->identifier);
    }

    /**
     * @param  array<int, mixed>  $records
     * @return array<int, array<string, mixed>>
     */
    protected function prunableRecords(array $records): array
    {
        $cutoff = $this->retention === null
            ? null
            : CarbonImmutable::now()->subSeconds($this->retention);

        $filtered = array_values(array_filter($records, static function (mixed $record) use ($cutoff): bool {
            if (! is_array($record) || ! isset($record['occurred_at'])) {
                return false;
            }

            try {
                $occurredAt = CarbonImmutable::parse((string) $record['occurred_at']);
            } catch (\Throwable) {
                return false;
            }

            return $cutoff === null || $occurredAt->greaterThanOrEqualTo($cutoff);
        }));

        return array_slice($filtered, -$this->perScopeLimit);
    }

    protected function rememberScope(CacheScope $scope): void
    {
        $scopes = $this->knownScopes();

        if (in_array($scope->identifier, $scopes, true)) {
            return;
        }

        $scopes[] = $scope->identifier;

        $this->store()->forever($this->knownScopesKey(), array_values($scopes));
    }

    protected function forgetScope(CacheScope $scope): void
    {
        $scopes = array_values(array_filter(
            $this->knownScopes(),
            static fn (string $identifier): bool => $identifier !== $scope->identifier
        ));

        if ($scopes === []) {
            $this->store()->forget($this->knownScopesKey());

            return;
        }

        $this->store()->forever($this->knownScopesKey(), $scopes);
    }

    /**
     * @return array<int, string>
     */
    protected function knownScopes(): array
    {
        $scopes = $this->store()->get($this->knownScopesKey(), []);

        if (! is_array($scopes)) {
            return [];
        }

        return array_values(array_unique(array_filter($scopes, 'is_string')));
    }

    protected function knownScopesKey(): string
    {
        return $this->prefix.':scopes';
    }

    protected function store(): Repository
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
