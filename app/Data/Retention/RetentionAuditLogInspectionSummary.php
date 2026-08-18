<?php

namespace App\Data\Retention;

use Illuminate\Support\Carbon;

readonly class RetentionAuditLogInspectionSummary
{
    /**
     * @param  array<string, int>  $rowsOlderThanDays  keyed by cohort label (e.g. 12_months)
     * @param  array<string, int>  $countByEvent
     * @param  list<array{event: string, count: int}>  $topEventsByVolume
     * @param  array<string, int>  $countByCategory
     * @param  array<string, int>  $mustKeepFamilyCounts
     * @param  array<string, array{label: string, count: int, older_than_days: int, estimated_payload_bytes: int, overlapping_must_keep_count: int}>  $candidateCohorts
     * @param  list<array{key: string, label: string, classification: string, readers: list<string>, notes: string}>  $logicalSafety
     * @param  array<string, mixed>  $truncationIssue
     */
    public function __construct(
        public Carbon $inspectedAt,
        public int $tableTotalRows,
        public ?int $tableDataBytes,
        public ?int $tableIndexBytes,
        public ?int $tableTotalBytes,
        public ?string $minCreatedAt,
        public ?string $maxCreatedAt,
        public array $rowsOlderThanDays,
        public array $countByEvent,
        public array $topEventsByVolume,
        public int $estimatedPayloadBytes,
        public array $countByCategory,
        public array $mustKeepFamilyCounts,
        public int $mustKeepFamilyRowTotal,
        public array $candidateCohorts,
        public array $logicalSafety,
        public array $truncationIssue,
    ) {}
}
