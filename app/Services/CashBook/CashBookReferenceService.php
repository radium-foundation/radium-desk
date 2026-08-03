<?php

namespace App\Services\CashBook;

use App\Models\CashBookEntry;
use Illuminate\Support\Facades\DB;

class CashBookReferenceService
{
    public function generate(): string
    {
        return DB::transaction(function (): string {
            $year = now()->format('Y');
            $prefix = "CB-{$year}-";

            $latestReference = CashBookEntry::query()
                ->withTrashed()
                ->where('entry_no', 'like', $prefix.'%')
                ->lockForUpdate()
                ->orderByDesc('entry_no')
                ->value('entry_no');

            $sequence = $latestReference
                ? ((int) substr((string) $latestReference, -6)) + 1
                : 1;

            return $prefix.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
        });
    }
}
