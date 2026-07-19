<?php

namespace App\Support\Assignment\Contracts\Performance;

use App\Models\User;

interface FirstResponseMetric
{
    public function averageMinutes(User $user): ?float;
}
