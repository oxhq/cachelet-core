<?php

namespace Oxhq\Cachelet\ValueObjects;

readonly class InterventionPreview
{
    /**
     * @param  array<int, array<string, mixed>>  $coordinateSummaries
     * @param  array<int, array<string, mixed>>  $storeSummaries
     * @param  array<int, string>  $caveats
     */
    public function __construct(
        public CacheScope $scope,
        public string $strategy,
        public int $matchedCoordinateCount,
        public array $coordinateSummaries,
        public array $storeSummaries,
        public string $blastRadius,
        public array $caveats = [],
        public bool $observedStateOnly = true,
    ) {}

    public function toArray(): array
    {
        return [
            'contract' => 'cachelet.intervention.preview.v1',
            'scope' => $this->scope->toProjection(),
            'strategy' => $this->strategy,
            'observed_state_only' => $this->observedStateOnly,
            'matched_coordinate_count' => $this->matchedCoordinateCount,
            'coordinate_summaries' => $this->coordinateSummaries,
            'store_summaries' => $this->storeSummaries,
            'blast_radius' => $this->blastRadius,
            'caveats' => $this->caveats,
        ];
    }
}
