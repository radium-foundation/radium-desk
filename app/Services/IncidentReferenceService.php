<?php

namespace App\Services;

use App\Models\Incident;
use Illuminate\Support\Facades\DB;

class IncidentReferenceService
{
    public function generate(): string
    {
        return DB::transaction(function (): string {
            $prefix = 'SC';

            $latestSequence = Incident::withTrashed()
                ->where(function ($query) {
                    $query->where('reference_no', 'like', 'SC-%')
                        ->orWhere('reference_no', 'like', 'SC%');
                })
                ->lockForUpdate()
                ->pluck('reference_no')
                ->map(fn (string $reference): int => $this->extractSequence($reference))
                ->max();

            $sequence = ($latestSequence ?? 0) + 1;

            return $prefix.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
        });
    }

    private function extractSequence(string $reference): int
    {
        if (preg_match('/^SC-?(\d+)$/i', $reference, $matches) === 1) {
            return (int) $matches[1];
        }

        return 0;
    }
}
