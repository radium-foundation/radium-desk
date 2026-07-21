<?php

namespace App\Data\Assignment;

readonly class AssignmentOriginRepairSummary
{
    /**
     * @param  list<AssignmentOriginRepairRow>  $changedRows
     * @param  list<array{incident_id: int, reason: string}>  $errorDetails
     */
    public function __construct(
        public bool $dryRun,
        public int $scanned,
        public int $changed,
        public int $skipped,
        public int $errors,
        public array $changedRows = [],
        public array $errorDetails = [],
    ) {}
}
