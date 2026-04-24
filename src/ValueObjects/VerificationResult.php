<?php

namespace Oxhq\Cachelet\ValueObjects;

readonly class VerificationResult
{
    /**
     * @param  array<int, array<string, mixed>>  $freshEvidence
     * @param  array<int, array<string, mixed>>  $staleEvidence
     * @param  array<int, string>  $caveats
     */
    public function __construct(
        public CacheScope $scope,
        public string $status,
        public array $freshEvidence = [],
        public array $staleEvidence = [],
        public array $caveats = [],
        public bool $observedStateOnly = true,
    ) {}

    public function toArray(): array
    {
        return [
            'contract' => 'cachelet.intervention.verification.v1',
            'scope' => $this->scope->toProjection(),
            'status' => $this->status,
            'observed_state_only' => $this->observedStateOnly,
            'fresh_evidence' => $this->freshEvidence,
            'stale_evidence' => $this->staleEvidence,
            'caveats' => $this->caveats,
        ];
    }
}
