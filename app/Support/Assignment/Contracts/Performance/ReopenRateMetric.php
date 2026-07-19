<?php

namespace App\Support\Assignment\Contracts\Performance;

use App\Models\User;

interface ReopenRateMetric
{
    public function reopenPercentage(User $user): ?float;
}
