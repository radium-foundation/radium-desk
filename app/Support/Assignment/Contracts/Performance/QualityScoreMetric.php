<?php

namespace App\Support\Assignment\Contracts\Performance;

use App\Models\User;

interface QualityScoreMetric
{
    public function score(User $user): ?float;
}
