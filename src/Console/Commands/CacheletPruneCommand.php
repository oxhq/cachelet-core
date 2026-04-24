<?php

namespace Oxhq\Cachelet\Console\Commands;

use Illuminate\Console\Command;
use Oxhq\Cachelet\Support\CoordinateLogger;
use Oxhq\Cachelet\Support\TelemetryStore;

class CacheletPruneCommand extends Command
{
    protected $signature = 'cachelet:prune';

    protected $description = 'Prune orphaned Cachelet registry and telemetry sidecar state';

    public function handle(CoordinateLogger $logger, TelemetryStore $telemetry): int
    {
        $registry = $logger->prune();
        $telemetrySummary = $telemetry->prune();

        $this->line(sprintf(
            'Registry: scanned %d prefixes / %d scopes, removed %d coordinates, %d prefixes, %d scopes.',
            $registry['scanned_prefixes'] ?? 0,
            $registry['scanned_scopes'] ?? 0,
            $registry['removed_coordinates'] ?? 0,
            $registry['removed_prefixes'] ?? 0,
            $registry['removed_scopes'] ?? 0,
        ));

        $this->line(sprintf(
            'Telemetry: scanned %d scopes, removed %d records and %d scopes.',
            $telemetrySummary['scanned_scopes'] ?? 0,
            $telemetrySummary['removed_records'] ?? 0,
            $telemetrySummary['removed_scopes'] ?? 0,
        ));

        return self::SUCCESS;
    }
}
