<?php

namespace App\Support\Repair\Data;

use App\Support\Repair\Enums\RepairBatchStatus;
use App\Support\Repair\Enums\RepairPhase;

readonly class RepairBatchSummary
{
    /**
     * @param  array<string, int>  $counts
     * @param  list<array<string, mixed>>  $samples
     * @param  list<array<string, mixed>>  $failures
     * @param  array<string, string|null>  $reportPaths
     */
    public function __construct(
        public string $batchUuid,
        public string $repairKey,
        public RepairPhase $phase,
        public RepairBatchStatus $status,
        public array $counts,
        public float $elapsedSeconds,
        public array $samples = [],
        public array $failures = [],
        public array $reportPaths = [],
        public ?string $errorSummary = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'batch_uuid' => $this->batchUuid,
            'repair_key' => $this->repairKey,
            'phase' => $this->phase->value,
            'status' => $this->status->value,
            'counts' => $this->counts,
            'elapsed_seconds' => $this->elapsedSeconds,
            'samples' => $this->samples,
            'failures' => $this->failures,
            'report_paths' => $this->reportPaths,
            'error_summary' => $this->errorSummary,
        ];
    }
}
