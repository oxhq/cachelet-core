<?php

namespace Oxhq\Cachelet\Events;

use Oxhq\Cachelet\ValueObjects\CacheTelemetryRecord;

class CacheletTelemetryRecorded
{
    public function __construct(
        public CacheTelemetryRecord $record
    ) {}
}
