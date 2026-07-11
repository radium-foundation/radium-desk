<?php

namespace App\Services\CustomerCorrection;

use App\Data\Eligibility\EligibilityResult;
use App\Models\Incident;
use App\Models\User;
use App\Services\IdentityCorrection\IdentityCorrectionEligibilityEvaluator;

class CustomerCorrectionEligibilityService
{
    public function __construct(
        private readonly IdentityCorrectionEligibilityEvaluator $evaluator,
    ) {}

    public function evaluate(Incident $incident, User $user): EligibilityResult
    {
        return $this->evaluator->evaluateBase(
            $incident,
            $user,
            IdentityCorrectionEligibilityEvaluator::KIND_CUSTOMER,
        );
    }

    public function canShowAction(Incident $incident, User $user): bool
    {
        return $this->evaluate($incident, $user)->allowed;
    }
}
