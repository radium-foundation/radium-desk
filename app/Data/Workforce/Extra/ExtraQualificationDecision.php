<?php

namespace App\Data\Workforce\Extra;

use App\Enums\AttendanceDayStatus;
use App\Enums\ContributionVerdict;
use App\Enums\ExtraQualificationContext;
use App\Enums\ExtraQualificationReason;
use Illuminate\Support\Carbon;

/**
 * Readonly Extra qualification decision — never mutates Attendance.
 */
readonly class ExtraQualificationDecision
{
    public function __construct(
        public int $userId,
        public Carbon $workDate,
        public bool $qualified,
        public ExtraQualificationReason $reason,
        public string $ruleUsed,
        public ExtraQualificationContext $context,
        public ?ContributionVerdict $contributionVerdict,
        public ?AttendanceDayStatus $attendanceState,
        public bool $engineEnabled,
        public ?string $proposedOutcome = null,
    ) {}

    public function isQualified(): bool
    {
        return $this->qualified;
    }
}
