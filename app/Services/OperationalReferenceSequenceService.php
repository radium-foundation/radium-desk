<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Transactional allocator for independent operational reference counters.
 *
 * Each series uses a dedicated row in reference_sequences. Counters are never
 * derived from database primary keys. Historical references are never rewritten.
 */
class OperationalReferenceSequenceService
{
    public function allocate(string $sequenceName, int $floor, string $prefix): string
    {
        return DB::transaction(function () use ($sequenceName, $floor, $prefix): string {
            $next = $this->nextValue($sequenceName, $floor);

            DB::table('reference_sequences')
                ->where('name', $sequenceName)
                ->update([
                    'current_value' => $next,
                    'updated_at' => now(),
                ]);

            return $prefix.$next;
        });
    }

    public function peekNext(string $sequenceName, int $floor): int
    {
        $row = DB::table('reference_sequences')
            ->where('name', $sequenceName)
            ->first();

        if ($row === null) {
            throw new RuntimeException("Operational reference sequence [{$sequenceName}] is not initialized.");
        }

        return max(((int) $row->current_value) + 1, $floor);
    }

    private function nextValue(string $sequenceName, int $floor): int
    {
        $row = DB::table('reference_sequences')
            ->where('name', $sequenceName)
            ->lockForUpdate()
            ->first();

        if ($row === null) {
            throw new RuntimeException("Operational reference sequence [{$sequenceName}] is not initialized.");
        }

        $next = ((int) $row->current_value) + 1;

        return max($next, $floor);
    }
}
