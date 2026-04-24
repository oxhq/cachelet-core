<?php

namespace Oxhq\Cachelet\Interventions;

use Carbon\CarbonImmutable;
use Oxhq\Cachelet\Support\CoordinateLogger;
use Oxhq\Cachelet\Support\TelemetryStore;
use Oxhq\Cachelet\ValueObjects\CacheCoordinate;
use Oxhq\Cachelet\ValueObjects\CacheScope;
use Oxhq\Cachelet\ValueObjects\InterventionPreview;
use Oxhq\Cachelet\ValueObjects\InterventionReceipt;
use Oxhq\Cachelet\ValueObjects\VerificationResult;

class ScopedIntervention
{
    public function __construct(
        protected CacheScope $scope,
        protected CoordinateLogger $logger,
        protected TelemetryStore $telemetry,
    ) {}

    public function preview(): InterventionPreview
    {
        $coordinates = $this->matchedCoordinates();

        return new InterventionPreview(
            scope: $this->scope,
            strategy: 'scope',
            matchedCoordinateCount: count($coordinates),
            coordinateSummaries: array_map([$this, 'coordinateSummary'], $coordinates),
            storeSummaries: $this->storeSummaries($coordinates),
            blastRadius: $this->blastRadius(count($coordinates)),
            caveats: [
                'Observed state only.',
                'May not include unseen entries.',
                'Does not imply business bug resolution.',
            ],
        );
    }

    public function execute(): InterventionReceipt
    {
        $coordinates = $this->matchedCoordinates();
        $summaries = array_map([$this, 'coordinateSummary'], $coordinates);
        $storeSummaries = $this->storeSummaries($coordinates);

        $this->logger->forgetScope($this->scope);

        return new InterventionReceipt(
            scope: $this->scope,
            strategy: 'scope',
            status: 'executed',
            executedAt: CarbonImmutable::now()->toIso8601String(),
            matchedCoordinateCount: count($coordinates),
            coordinateSummaries: $summaries,
            storeSummaries: $storeSummaries,
        );
    }

    public function verify(InterventionReceipt $receipt): VerificationResult
    {
        if ($receipt->status !== 'executed') {
            return new VerificationResult(
                scope: $receipt->scope,
                status: 'failed',
                caveats: [
                    'Intervention did not execute successfully.',
                ],
            );
        }

        $records = $this->telemetry->recordsForScopeSince($receipt->scope, $receipt->executedAt);
        $freshEvidence = array_values(array_filter($records, function (array $record): bool {
            if (($record['event'] ?? null) === 'stored') {
                return true;
            }

            if (($record['event'] ?? null) !== 'hit') {
                return false;
            }

            return data_get($record, 'context.entry_state') === 'fresh'
                && data_get($record, 'context.swr_runtime.served_stale', false) === false;
        }));
        $staleEvidence = array_values(array_filter($records, static function (array $record): bool {
            return data_get($record, 'context.swr_runtime.served_stale', false) === true;
        }));

        $status = match (true) {
            $freshEvidence === [] && $staleEvidence === [] => 'inconclusive',
            $freshEvidence !== [] && $staleEvidence === [] => 'verified',
            $freshEvidence !== [] => 'recovering',
            default => 'inconclusive',
        };

        return new VerificationResult(
            scope: $receipt->scope,
            status: $status,
            freshEvidence: $freshEvidence,
            staleEvidence: $staleEvidence,
            caveats: [
                'Observed state only.',
                'Does not imply business bug resolution.',
            ],
        );
    }

    /**
     * @return array<int, CacheCoordinate>
     */
    protected function matchedCoordinates(): array
    {
        return $this->logger->coordinatesForScope($this->scope);
    }

    /**
     * @return array<string, mixed>
     */
    protected function coordinateSummary(CacheCoordinate $coordinate): array
    {
        return [
            'key' => $coordinate->key,
            'prefix' => $coordinate->prefix,
            'module' => $coordinate->module,
            'store' => $coordinate->store,
            'tags' => $coordinate->tags,
        ];
    }

    /**
     * @param  array<int, CacheCoordinate>  $coordinates
     * @return array<int, array<string, mixed>>
     */
    protected function storeSummaries(array $coordinates): array
    {
        $stores = [];

        foreach ($coordinates as $coordinate) {
            $name = $coordinate->store ?? 'default';

            if (! isset($stores[$name])) {
                $stores[$name] = [
                    'store' => $name,
                    'coordinate_count' => 0,
                    'modules' => [],
                ];
            }

            $stores[$name]['coordinate_count']++;

            if (! in_array($coordinate->module, $stores[$name]['modules'], true)) {
                $stores[$name]['modules'][] = $coordinate->module;
            }
        }

        return array_values($stores);
    }

    protected function blastRadius(int $count): string
    {
        return match (true) {
            $count >= 25 => 'high',
            $count >= 5 => 'medium',
            default => 'low',
        };
    }
}
