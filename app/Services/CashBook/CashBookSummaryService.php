<?php

namespace App\Services\CashBook;

use App\Enums\CashBookEntryType;
use App\Models\CashBookEntry;
use Illuminate\Support\Carbon;

/**
 * Operational Cash Book totals. Handover terms are reserved for Phase 2 (always 0 here).
 *
 * Available Cash = All Income − All Expense − Handed Over + Received Back
 */
class CashBookSummaryService
{
    /**
     * @return array{
     *     todays_income: float,
     *     todays_expense: float,
     *     all_income: float,
     *     all_expense: float,
     *     cash_handed_over: float,
     *     cash_received_back: float,
     *     available_cash: float
     * }
     */
    public function dashboard(?Carbon $today = null): array
    {
        $today = ($today ?? now())->toDateString();

        $totals = CashBookEntry::query()
            ->selectRaw('type, SUM(amount) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $todayTotals = CashBookEntry::query()
            ->selectRaw('type, SUM(amount) as total')
            ->whereDate('entry_date', $today)
            ->groupBy('type')
            ->pluck('total', 'type');

        $allIncome = round((float) ($totals[CashBookEntryType::Income->value] ?? 0), 2);
        $allExpense = round((float) ($totals[CashBookEntryType::Expense->value] ?? 0), 2);
        $todaysIncome = round((float) ($todayTotals[CashBookEntryType::Income->value] ?? 0), 2);
        $todaysExpense = round((float) ($todayTotals[CashBookEntryType::Expense->value] ?? 0), 2);

        // Phase 1: handover not implemented.
        $cashHandedOver = 0.0;
        $cashReceivedBack = 0.0;

        $availableCash = round(
            $allIncome - $allExpense - $cashHandedOver + $cashReceivedBack,
            2,
        );

        return [
            'todays_income' => $todaysIncome,
            'todays_expense' => $todaysExpense,
            'all_income' => $allIncome,
            'all_expense' => $allExpense,
            'cash_handed_over' => $cashHandedOver,
            'cash_received_back' => $cashReceivedBack,
            'available_cash' => $availableCash,
        ];
    }
}
