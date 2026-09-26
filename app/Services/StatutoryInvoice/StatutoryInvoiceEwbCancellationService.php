<?php

namespace App\Services\StatutoryInvoice;

use App\Models\StatutoryInvoice;

/**
 * Desk does not persist e-way bills. This service records explicit policy outcomes only.
 */
final class StatutoryInvoiceEwbCancellationService
{
    /**
     * @return array{status: string, message: string}
     */
    public function cancelIfApplicable(StatutoryInvoice $invoice, string $reason): array
    {
        return [
            'status' => 'not_applicable',
            'message' => 'Desk does not store e-way bills; no EWB cancellation was attempted.',
        ];
    }
}
