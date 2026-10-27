<?php

namespace App\Services\StatutoryInvoice;

use App\Models\StatutoryInvoiceCancellation;
use Illuminate\Support\Facades\Log;

final class StatutoryInvoiceCancellationManualReviewAlert
{
    public function emit(StatutoryInvoiceCancellation $operation, string $reason, array $context = []): void
    {
        Log::warning('statutory_invoice_cancellation.manual_review_required', array_merge([
            'cancellation_id' => $operation->id,
            'statutory_invoice_id' => $operation->statutory_invoice_id,
            'correlation_id' => $operation->correlation_id,
            'status' => $operation->status?->value ?? (string) $operation->getRawOriginal('status'),
            'reason' => $reason,
        ], $context));
    }
}
