<?php

namespace App\Policies;

use App\Models\User;
use App\Services\DashboardPersonalizationService;

class DashboardPolicy
{
    public function viewHardware(User $user): bool
    {
        return $user->can(DashboardPersonalizationService::PERMISSION_HARDWARE_VIEW);
    }
}
