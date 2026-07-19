<?php

namespace App\Support\Assignment\Contracts\Performance;

use App\Models\User;

interface ResolutionTimeMetric
{
    public function averageMinutes(User $user): ?float;
}
