<?php

namespace App\Services\Finance;

use App\Models\FinanceExpense;
use Illuminate\Support\Facades\DB;

class FinanceExpenseReferenceService
{
    public function generate(): string
    {
        return DB::transaction(function (): string {
            $year = now()->format('Y');
            $prefix = "EXP-{$year}-";

            $latestReference = FinanceExpense::query()
                ->where('expense_no', 'like', $prefix.'%')
                ->lockForUpdate()
                ->orderByDesc('expense_no')
                ->value('expense_no');

            $sequence = $latestReference
                ? ((int) substr($latestReference, -6)) + 1
                : 1;

            return $prefix.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
        });
    }
}
