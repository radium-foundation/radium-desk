<?php

namespace App\Services\StatutoryInvoice;

use App\Models\CommerceOrder;
use App\Models\ServiceStatutoryGstMismatchException;
use Illuminate\Support\Facades\DB;

final class ServiceStatutoryGstMismatchFallbackService
{
    public const FALLBACK_REASON = 'GST mismatch unresolved after 72-hour customer verification window.';

    public function __construct(
        private readonly ServiceStatutoryGstMismatchExceptionService $exceptions,
        private readonly StatutoryInvoiceService $invoices,
    ) {}

    public function queueB2cFallback(ServiceStatutoryGstMismatchException $exception): void
    {
        if ($exception->status->isTerminal()) {
            return;
        }

        if ($exception->corrected_at !== null) {
            return;
        }

        $commerce = $exception->commerceOrder;
        if ($commerce !== null && $commerce->statutory_invoice_id !== null) {
            return;
        }

        $this->exceptions->transitionToFallbackPending($exception);
        $this->issueB2cFallback($exception->fresh() ?? $exception);
    }

    public function issueB2cFallback(ServiceStatutoryGstMismatchException $exception): void
    {
        DB::transaction(function () use ($exception): void {
            $locked = ServiceStatutoryGstMismatchException::query()
                ->whereKey($exception->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status->isTerminal()) {
                return;
            }

            $supportOrder = $locked->supportOrder;
            if ($supportOrder === null) {
                return;
            }

            $commerce = CommerceOrder::query()->whereKey($locked->commerce_order_id)->lockForUpdate()->firstOrFail();
            if ($commerce->statutory_invoice_id !== null) {
                $this->exceptions->markB2cIssued($locked, (int) $commerce->statutory_invoice_id, self::FALLBACK_REASON);

                return;
            }

            if ($locked->corrected_at !== null) {
                return;
            }

            $invoice = $this->invoices->issueB2cGstVerificationFallbackFromSupportOrder(
                $supportOrder,
                null,
                self::FALLBACK_REASON,
            );

            $this->exceptions->markB2cIssued($locked, $invoice->id, self::FALLBACK_REASON);
        });
    }
}
