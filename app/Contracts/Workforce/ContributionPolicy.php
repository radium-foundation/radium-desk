<?php

namespace App\Contracts\Workforce;

use App\Data\Workforce\Contribution\ContributionEvaluation;
use App\Data\Workforce\Contribution\ContributionSnapshot;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Contribution policy port — evaluates packs/signals into a verdict.
 * Must never modify Attendance.
 */
interface ContributionPolicy extends WorkforcePolicy
{
    public function evaluate(ContributionSnapshot $snapshot): ContributionEvaluation;

    public function resolvePack(User $user): \App\Data\Workforce\Contribution\ContributionPack;
}
