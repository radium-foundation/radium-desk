<?php

namespace App\Services\HardwareFulfilment;

use App\Enums\HardwareFulfilmentState;
use App\Models\HardwareFulfilment;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * After-commit hardware invoice trigger. Serial allocation must already
 * have committed. WhiteBooks GENERATE is not called here; IRN is queued
 * by HardwareFulfilmentInvoiceService after mint.
 */
final class HardwareStatutoryInvoiceIssuer
{
    public function __construct(
        private readonly HardwareFulfilmentInvoiceService $invoices,
    ) {}

    public function issueAfterSerialsAllocated(HardwareFulfilment $fulfilment, ?User $actor = null): void
    {
        $fresh = $fulfilment->fresh() ?? $fulfilment;
        if ($fresh->state !== HardwareFulfilmentState::SerialsAllocated
            && $fresh->state !== HardwareFulfilmentState::InvoiceIssued) {
            return;
        }

        try {
            $this->invoices->issueInvoice($fresh, $actor);
        } catch (Throwable $exception) {
            Log::warning('hardware_statutory_invoice.serial_issue_failed', [
                'fulfilment_id' => $fresh->id,
                'source_id' => $fresh->source_id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
