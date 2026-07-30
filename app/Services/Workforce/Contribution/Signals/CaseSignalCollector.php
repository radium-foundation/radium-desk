<?php

namespace App\Services\Workforce\Contribution\Signals;

use App\Contracts\Workforce\ContributionSignalCollector;
use App\Data\Workforce\Contribution\ContributionSignal;
use App\Enums\ContributionSignalId;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Adapts work_sessions.cases_handled_count (Presence CaseAction increments).
 */
final class CaseSignalCollector implements ContributionSignalCollector
{
    public function id(): ContributionSignalId
    {
        return ContributionSignalId::CasesHandled;
    }

    public function collect(User $user, Carbon $workDate, Collection $sessions): ContributionSignal
    {
        return new ContributionSignal(
            id: $this->id(),
            value: (int) $sessions->sum('cases_handled_count'),
            unit: $this->id()->unit(),
            available: true,
        );
    }
}
