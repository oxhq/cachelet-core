<?php

namespace Oxhq\Cachelet\Support;

use Oxhq\Cachelet\Events\CacheletTelemetryRecorded;
use Oxhq\Cachelet\ValueObjects\CacheCoordinate;
use Oxhq\Cachelet\ValueObjects\CacheTelemetryRecord;

class CacheTelemetryEmitter
{
    public function emit(string $event, CacheCoordinate $coordinate, array $context = []): void
    {
        event(new CacheletTelemetryRecorded(
            CacheTelemetryRecord::capture($event, $coordinate, $context)
        ));
    }
}
