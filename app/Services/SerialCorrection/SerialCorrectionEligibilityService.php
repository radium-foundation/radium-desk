<?php

namespace App\Services\SerialCorrection;

use App\Models\Incident;
use App\Models\User;

class SerialCorrectionEligibilityService
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

        if (! $incident->order->isSerialLocked()) {
            return false;
        }

        return $user->can('correctIdentity', $incident->order);
    }
}
