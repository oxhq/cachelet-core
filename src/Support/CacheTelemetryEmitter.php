<?php

namespace Oxhq\Cachelet\Support;

use Oxhq\Cachelet\Events\CacheletTelemetryRecorded;
use Oxhq\Cachelet\ValueObjects\CacheCoordinate;
use Oxhq\Cachelet\ValueObjects\CacheTelemetryRecord;

class CacheTelemetryEmitter
{
    public function __construct(
        protected ?TelemetryStore $store = null,
    ) {}

    public function emit(string $event, CacheCoordinate $coordinate, array $context = []): void
    {
        $record = CacheTelemetryRecord::capture($event, $coordinate, $context);

        $this->store?->record($record);

        event(new CacheletTelemetryRecorded($record));
    }
}
