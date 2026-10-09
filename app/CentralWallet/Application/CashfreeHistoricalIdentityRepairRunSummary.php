<?php

namespace App\CentralWallet\Application;

final class CashfreeHistoricalIdentityRepairRunSummary
{
    /**
     * @param  array<string, mixed>  $metrics
     * @param  list<array<string, mixed>>  $cohortSamples
     * @param  array<string, mixed>|null  $rd16854
     */
    public function __construct(
        public readonly string $runId,
        public readonly string $mode,
        public readonly array $metrics,
        public readonly int $plannedMutations,
        public readonly int $errors,
        public readonly array $cohortSamples,
        public readonly ?array $rd16854,
    ) {}
}
