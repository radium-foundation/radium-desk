<?php

namespace App\Services\Finance;

use App\Models\FinanceJournal;
use Illuminate\Support\Facades\DB;

class FinanceJournalReferenceService
{
    public function generate(): string
    {
        $year = now()->format('Y');
        $prefix = 'JRN-'.$year.'-';

        return DB::transaction(function () use ($prefix): string {
            $latest = FinanceJournal::query()
                ->where('journal_no', 'like', $prefix.'%')
                ->lockForUpdate()
                ->orderByDesc('journal_no')
                ->value('journal_no');

            $next = 1;
            if (is_string($latest) && preg_match('/^JRN-\d{4}-(\d+)$/', $latest, $matches) === 1) {
                $next = ((int) $matches[1]) + 1;
            }

            return $prefix.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
        });
    }
}
