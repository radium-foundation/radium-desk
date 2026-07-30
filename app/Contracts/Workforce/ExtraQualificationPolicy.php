<?php

namespace App\Contracts\Workforce;

use App\Data\Workforce\Contribution\ContributionEvaluation;
use App\Data\Workforce\Extra\ExtraQualificationDecision;
use App\Models\User;
use App\Models\WorkforceAttendanceDay;
use Illuminate\Support\Carbon;

/**
 * Extra Working Day (EX) qualification policy — separate from Attendance SoT.
 * Must never modify Attendance.
 */
interface ExtraQualificationPolicy extends WorkforcePolicy
{
    public function decide(
        User $user,
        Carbon $workDate,
        ?WorkforceAttendanceDay $attendance,
        ContributionEvaluation $contribution,
        bool $engineEnabled,
    ): ExtraQualificationDecision;
}
