<?php

namespace App\Services\StatutoryInvoice;

use Illuminate\Validation\ValidationException;

/**
 * Explicit boundary: introducing statutory invoices must not double-count
 * revenue that POS/Cashfree already posted to GL account 4000.
 */
final class StatutoryInvoiceAccountingPolicy
{
    public function assertJournalsMustNotPost(): void
    {
        if (config('statutory_invoices.post_finance_journals')) {
            throw ValidationException::withMessages([
                'finance' => 'Statutory invoice GL posting is disabled until the CA recognition policy is decided. Revenue remains on the existing POS/order payment journals.',
            ]);
        }
    }

    public function assertMustNotAutoIssueOnPosComplete(): void
    {
        if (config('statutory_invoices.auto_issue_on_pos_complete')) {
            throw ValidationException::withMessages([
                'invoice' => 'Auto-issuing a statutory invoice on POS complete is disabled until invoice-on-payment vs invoice-on-dispatch is decided.',
            ]);
        }
    }
}
