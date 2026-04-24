<?php

namespace Oxhq\Cachelet\Interventions;

use Oxhq\Cachelet\Support\CoordinateLogger;
use Oxhq\Cachelet\Support\TelemetryStore;
use Oxhq\Cachelet\ValueObjects\CacheScope;

class InterventionManager
{
    public function __construct(
        protected CoordinateLogger $logger,
        protected TelemetryStore $telemetry,
    ) {}

    public function forScope(CacheScope|string $scope): ScopedIntervention
    {
        return new ScopedIntervention(
            $scope instanceof CacheScope ? $scope : CacheScope::explicit((string) $scope),
            $this->logger,
            $this->telemetry,
        );
    }
}
