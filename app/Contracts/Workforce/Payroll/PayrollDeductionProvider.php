<?php

namespace App\Contracts\Workforce\Payroll;

use App\Data\Workforce\Payroll\PayrollMonthResult;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Phase 3+ extension: PF, ESI, PT, TDS, loans, advances.
 * Phase 1 returns zero / no-op adjustments.
 */
interface PayrollDeductionProvider
{
    /**
     * @return list<array{code: string, label: string, amount: float}>
     */
    public function deductionsFor(User $user, Carbon $month, PayrollMonthResult $baseResult): array;
}
