<?php

namespace App\Services;

use App\Models\ApprovalNumber;
use Illuminate\Support\Facades\DB;

class ApprovalReferenceService
{
    public function generate(): string
    {
        return DB::transaction(function (): string {
            $year = now()->format('Y');
            $prefix = "AP-{$year}-";

            $latestReference = ApprovalNumber::withTrashed()
                ->where('approval_number', 'like', $prefix.'%')
                ->lockForUpdate()
                ->orderByDesc('approval_number')
                ->value('approval_number');

            $sequence = $latestReference
                ? ((int) substr($latestReference, -6)) + 1
                : 1;

            return $prefix.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
        });
    }
}
