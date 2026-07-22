<?php

namespace App\Support\Repair\Services;

use App\Support\Repair\Models\SystemRepairBatch;

class RepairCheckpointService
{
    /**
     * @param  array<string, int>  $counts
     */
    public function save(SystemRepairBatch $batch, ?int $lastSubjectId, array $counts): void
    {
        $batch->update([
            'checkpoint' => [
                'last_subject_id' => $lastSubjectId,
                'processed' => $counts['processed'] ?? 0,
                'repaired' => $counts['repaired'] ?? 0,
                'cleaned_up' => $counts['cleaned_up'] ?? 0,
                'skipped' => $counts['skipped'] ?? 0,
                'failed' => $counts['failed'] ?? 0,
                'updated_at' => now()->toIso8601String(),
            ],
            'counts' => $counts,
        ]);
    }

    public function lastSubjectId(SystemRepairBatch $batch): ?int
    {
        $checkpoint = $batch->checkpoint;

        if (! is_array($checkpoint) || ! isset($checkpoint['last_subject_id'])) {
            return null;
        }

        return is_numeric($checkpoint['last_subject_id'])
            ? (int) $checkpoint['last_subject_id']
            : null;
    }
}
