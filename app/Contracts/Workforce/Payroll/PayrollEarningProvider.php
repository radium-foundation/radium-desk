<?php

namespace App\Contracts\Workforce\Payroll;

use App\Data\Workforce\Payroll\PayrollMonthResult;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Phase 2+ extension: add earnings (OT, Extra, Recognition, Incentives).
 * Phase 1 returns zero / no-op adjustments.
 */
interface PayrollEarningProvider
{
    /**
     * @return list<array{code: string, label: string, amount: float}>
     */
    public function earningsFor(User $user, Carbon $month, PayrollMonthResult $baseResult): array;
}
