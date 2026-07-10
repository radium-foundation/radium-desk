<?php

namespace App\Services;

use App\Models\ReferenceSequence;
use Illuminate\Support\Facades\DB;

/**
 * Allocates SC reference numbers from the reference_sequences table.
 *
 * The row lock must only cover lock → increment → return. Callers that wrap
 * larger unit-of-work transactions (Cashfree payment persistence, customer
 * intake, etc.) must call generate() before opening that outer transaction so
 * InnoDB does not hold the sequence lock across order/incident writes.
 */
class IncidentReferenceService
{
    private const PREFIX = 'SC';

    public function generate(): string
    {
        return DB::transaction(function (): string {
            $sequence = $this->nextSequenceValue();

            return self::formatReference($sequence);
        });
    }

    public static function formatReference(int $sequence): string
    {
        return self::PREFIX.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
    }

    public static function extractSequenceNumber(string $reference): int
    {
        if (preg_match('/^SC-?(\d+)$/i', $reference, $matches) === 1) {
            return (int) $matches[1];
        }

        return 0;
    }

    private function nextSequenceValue(): int
    {
        $row = DB::table('reference_sequences')
            ->where('name', ReferenceSequence::SC)
            ->lockForUpdate()
            ->first();

        if ($row === null) {
            throw new \RuntimeException('SC reference sequence is not initialized.');
        }

        $next = ((int) $row->current_value) + 1;

        DB::table('reference_sequences')
            ->where('name', ReferenceSequence::SC)
            ->update([
                'current_value' => $next,
                'updated_at' => now(),
            ]);

        return $next;
    }
}
