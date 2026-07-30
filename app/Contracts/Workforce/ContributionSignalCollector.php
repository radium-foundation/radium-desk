<?php

namespace App\Contracts\Workforce;

use App\Data\Workforce\Contribution\ContributionSignal;
use App\Enums\ContributionSignalId;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Collects one contribution signal from existing metrics (no new calculations).
 */
interface ContributionSignalCollector
{
    public function id(): ContributionSignalId;

    /**
     * @param  Collection<int, \App\Models\WorkSession>  $sessions
     */
    public function collect(User $user, Carbon $workDate, Collection $sessions): ContributionSignal;
}
