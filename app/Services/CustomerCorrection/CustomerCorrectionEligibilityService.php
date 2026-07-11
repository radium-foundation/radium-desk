<?php

namespace App\Services\CustomerCorrection;

use App\Models\Incident;
use App\Models\User;

class CustomerCorrectionEligibilityService
{
    public function canShowAction(Incident $incident, User $user): bool
    {
        if (! $user->can('update', $incident)) {
            return false;
        }

        $incident->loadMissing('order');

        if ($incident->order === null) {
            return false;
        }

        return $user->can('correctIdentity', $incident->order);
    }
}
