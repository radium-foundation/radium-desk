<?php

namespace App\Services\StatutoryInvoice\Data;

use App\Enums\IrnCancellationDecision;
use App\Enums\StatutoryCancellationWorkflow;

final class StatutoryCancellationPolicyResult
{
    public function __construct(
        public readonly StatutoryCancellationWorkflow $workflow,
        public readonly IrnCancellationDecision $irnDecision,
        public readonly bool $isB2b,
        public readonly bool $hasSubmittedIrn,
        public readonly ?float $irnAgeHours = null,
        public readonly ?string $summary = null,
    ) {}

    public function requiresCreditNote(): bool
    {
        return $this->workflow === StatutoryCancellationWorkflow::B2bBeyondWindowCreditNote;
    }

    public function cancelsOriginalInvoice(): bool
    {
        return in_array($this->workflow, [
            StatutoryCancellationWorkflow::B2bWithinWindowCancelInvoice,
            StatutoryCancellationWorkflow::B2bNoIrnCancelInvoice,
            StatutoryCancellationWorkflow::B2cCancelInvoice,
        ], true);
    }

    public function requiresIrnCancellation(): bool
    {
        return $this->irnDecision === IrnCancellationDecision::CancellationRequired;
    }
}
