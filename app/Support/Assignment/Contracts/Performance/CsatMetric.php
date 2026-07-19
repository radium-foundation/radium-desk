<?php

namespace App\Support\Assignment\Contracts\Performance;

use App\Models\User;

interface CsatMetric
{
    public function averageScore(User $user): ?float;
}
