<?php

namespace App\Support\Assignment\Contracts\Performance;

use App\Models\User;

interface SlaComplianceMetric
{
    public function complianceRate(User $user): ?float;
}
