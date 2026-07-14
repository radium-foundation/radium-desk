<?php

namespace App\Services\DeviceModelCorrection;

use App\Data\Eligibility\EligibilityResult;
use App\Models\Incident;
use App\Models\User;
use App\Services\IdentityCorrection\IdentityCorrectionEligibilityEvaluator;

class DeviceModelCorrectionEligibilityService
{
    public function __construct(
        private readonly IdentityCorrectionEligibilityEvaluator $evaluator,
    ) {}

    public function evaluate(Incident $incident, User $user): EligibilityResult
    {
        $incident->loadMissing('order');

        if ($incident->order !== null && ! $incident->order->hasDeviceModelAssigned()) {
            return EligibilityResult::deny('No device model assigned yet.');
        }

        $result = $this->evaluator->evaluateBase(
            $incident,
            $user,
            IdentityCorrectionEligibilityEvaluator::KIND_DEVICE_MODEL,
        );

        if (! $result->allowed) {
            return $result;
        }

        return EligibilityResult::allow();
    }

    public function canShowAction(Incident $incident, User $user): bool
    {
        return $this->evaluate($incident, $user)->allowed;
    }
}
