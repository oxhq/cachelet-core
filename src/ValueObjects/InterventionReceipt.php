<?php

namespace Oxhq\Cachelet\ValueObjects;

readonly class InterventionReceipt
{
    /**
     * @param  array<int, array<string, mixed>>  $coordinateSummaries
     * @param  array<int, array<string, mixed>>  $storeSummaries
     */
    public function __construct(
        public CacheScope $scope,
        public string $strategy,
        public string $status,
        public string $executedAt,
        public int $matchedCoordinateCount,
        public array $coordinateSummaries = [],
        public array $storeSummaries = [],
    ) {}

    public function toArray(): array
    {
        return [
            'contract' => 'cachelet.intervention.receipt.v1',
            'scope' => $this->scope->toProjection(),
            'strategy' => $this->strategy,
            'status' => $this->status,
            'executed_at' => $this->executedAt,
            'matched_coordinate_count' => $this->matchedCoordinateCount,
            'coordinate_summaries' => $this->coordinateSummaries,
            'store_summaries' => $this->storeSummaries,
        ];
    }
}
