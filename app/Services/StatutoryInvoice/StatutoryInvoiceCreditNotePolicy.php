<?php

namespace App\Services\StatutoryInvoice;

use App\Models\StatutoryInvoice;

/**
 * Current-release credit-note policy.
 *
 * Target architecture (rd-central-finance-invoice-architecture.md §11) requires a
 * GST credit note after post-issue cancellation. Current release §23.4 explicitly
 * states credit notes are not issued; cancel keeps the tax-invoice number only.
 */
final class StatutoryInvoiceCreditNotePolicy
{
    public function canMint(): bool
    {
        return false;
    }

    public function isRequiredForCancellation(StatutoryInvoice $invoice): bool
    {
        return false;
    }

    public function requirementSummary(): string
    {
        return 'Credit notes are not issued in the current release; statutory cancellation keeps the original tax-invoice number only.';
    }

    /**
     * @return array{status: string, message: string}
     */
    public function actionForCancellation(StatutoryInvoice $invoice): array
    {
        if ($this->isRequiredForCancellation($invoice) && ! $this->canMint()) {
            return [
                'status' => 'blocked_requirements_not_met',
                'message' => 'A credit note is required for this cancellation but credit-note minting is not implemented.',
            ];
        }

        return [
            'status' => 'not_required_current_release',
            'message' => $this->requirementSummary(),
        ];
    }
}
