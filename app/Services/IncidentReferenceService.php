<?php

namespace App\Services;

use App\Models\Incident;
use Illuminate\Support\Facades\DB;

class IncidentReferenceService
{
    public function generate(): string
    {
        return DB::transaction(function (): string {
            $prefix = 'SC-';

            $latestReference = Incident::withTrashed()
                ->where('reference_no', 'like', $prefix.'%')
                ->lockForUpdate()
                ->orderByDesc('reference_no')
                ->value('reference_no');

            $sequence = $latestReference
                ? ((int) substr($latestReference, strlen($prefix))) + 1
                : 1;

            return $prefix.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
        });
    }
}
