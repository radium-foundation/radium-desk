<?php

namespace App\Support\Dashboard;

use App\Models\Incident;
use App\Support\Dashboard\Contracts\DashboardAttentionScoreCalculator;
use Illuminate\Support\Carbon;

final class NullDashboardAttentionScoreCalculator implements DashboardAttentionScoreCalculator
{
    public function score(Incident $incident, ?Carbon $now = null): int
    {
        return 0;
    }
}
