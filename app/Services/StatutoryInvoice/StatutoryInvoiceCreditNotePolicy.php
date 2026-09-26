<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\StatutoryCancellationWorkflow;
use App\Models\StatutoryInvoice;

final class StatutoryInvoiceCreditNotePolicy
{
    public function __construct(
        private readonly StatutoryInvoiceCancellationPolicyService $policy,
    ) {}

    public function canMint(): bool
    {
        return true;
    }

    public function isRequiredForCancellation(StatutoryInvoice $invoice): bool
    {
        return $this->policy->evaluate($invoice)->requiresCreditNote();
    }

    public function requirementSummary(): string
    {
        return 'B2B invoices with IRN older than the statutory cancellation window require a GST credit note; the original invoice remains active.';
    }

    /**
     * @return array{status: string, message: string}
     */
    public function actionForCancellation(StatutoryInvoice $invoice): array
    {
        $evaluation = $this->policy->evaluate($invoice);

        if ($evaluation->workflow === StatutoryCancellationWorkflow::B2bBeyondWindowCreditNote) {
            return [
                'status' => 'required',
                'message' => $evaluation->summary ?? $this->requirementSummary(),
            ];
        }

        if ($evaluation->workflow === StatutoryCancellationWorkflow::B2cAdjustmentPending) {
            return [
                'status' => 'b2c_adjustment_pending',
                'message' => 'B2C GST adjustment workflow is not fully implemented; policy decision is recorded explicitly.',
            ];
        }

        return [
            'status' => 'not_required',
            'message' => 'No credit note is required for this cancellation path.',
        ];
    }
}
