<?php

namespace App\Services\StatutoryInvoice;

/**
 * Per-invoice IRP submission hold. Reversible via config/env only.
 */
final class EInvoiceIrpSubmissionHold
{
    public static function isHeld(int $invoiceId): bool
    {
        /** @var list<int> $held */
        $held = config('statutory_invoices.einvoice.irp_submission_held_invoice_ids', []);

        return in_array($invoiceId, $held, true);
    }

    public static function holdReason(): string
    {
        return 'irp_submission_held';
    }
}
