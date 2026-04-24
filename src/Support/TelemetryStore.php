<?php

namespace Oxhq\Cachelet\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Oxhq\Cachelet\ValueObjects\CacheScope;
use Oxhq\Cachelet\ValueObjects\CacheTelemetryRecord;

class TelemetryStore
{
    public function record(CacheTelemetryRecord $record): void
    {
        $scope = $record->coordinate->scope;

        if (! $scope instanceof CacheScope) {
            return;
        }

        $key = $this->scopeKey($scope);
        $records = Cache::get($key, []);

        if (! is_array($records)) {
            $records = [];
        }

        $records[] = $record->toArray();
        $records = array_slice($records, -100);

        Cache::forever($key, $records);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recordsForScopeSince(CacheScope $scope, string $since): array
    {
        $records = Cache::get($this->scopeKey($scope), []);

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

    protected function scopeKey(CacheScope $scope): string
    {
        return 'cachelet:telemetry:scope:'.sha1($scope->identifier);
    }
}
