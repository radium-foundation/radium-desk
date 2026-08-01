<?php

namespace App\Contracts\Workforce;

use App\Data\Workforce\Recognition\RecognitionAward;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Payroll incentive port — consumes approved Work Recognition awards only.
 * Must never calculate or mutate Attendance.
 */
interface IncentivePolicy extends WorkforcePolicy
{
    /**
     * @return Collection<int, RecognitionAward>
     */
    public function approvedAwardsForMonth(Carbon $month): Collection;
}
