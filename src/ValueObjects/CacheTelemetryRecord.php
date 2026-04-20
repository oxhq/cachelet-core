<?php

namespace Oxhq\Cachelet\ValueObjects;

use Carbon\CarbonImmutable;

readonly class CacheTelemetryRecord
{
    public function __construct(
        public string $event,
        public CacheCoordinate $coordinate,
        public array $context = [],
        public string $occurredAt = '',
    ) {}

    public static function capture(string $event, CacheCoordinate $coordinate, array $context = []): self
    {
        return new self(
            event: $event,
            coordinate: $coordinate,
            context: $context,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
        );
    }

    public function toArray(): array
    {
        return [
            'contract' => 'cachelet.telemetry.v1',
            'event' => $this->event,
            'module' => $this->coordinate->module,
            'store' => $this->coordinate->store,
            'occurred_at' => $this->occurredAt,
            'coordinate' => $this->coordinate->toProjection(),
            'context' => $this->context,
        ];
    }
}
