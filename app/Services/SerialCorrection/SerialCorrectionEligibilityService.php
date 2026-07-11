<?php

namespace App\Services\SerialCorrection;

use App\Data\Eligibility\EligibilityResult;
use App\Models\Incident;
use App\Models\User;
use App\Services\IdentityCorrection\IdentityCorrectionEligibilityEvaluator;

class SerialCorrectionEligibilityService
{
    public function __construct(
        private readonly IdentityCorrectionEligibilityEvaluator $evaluator,
    ) {}

    public function evaluate(Incident $incident, User $user): EligibilityResult
    {
        $result = $this->evaluator->evaluateBase(
            $incident,
            $user,
            IdentityCorrectionEligibilityEvaluator::KIND_SERIAL,
        );

        if (! $result->allowed) {
            return $result;
        }

        if (! $incident->order->isSerialLocked()) {
            return EligibilityResult::deny('No serial assigned yet.');
        }

        return EligibilityResult::allow();
    }

    public function canShowAction(Incident $incident, User $user): bool
    {
        return $this->evaluate($incident, $user)->allowed;
    }
}
