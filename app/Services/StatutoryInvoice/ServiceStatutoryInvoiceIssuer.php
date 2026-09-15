<?php

namespace App\Services\StatutoryInvoice;

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * After-commit service-invoice trigger. Reference assign, waiting auto-close,
 * and operator/generic case close all share one commerce-order identity.
 * WhiteBooks GENERATE is not called here.
 *
 * Fail-closed eligibility / missing commerce rows are logged and do not
 * roll back the already-committed workflow action.
 */
final class ServiceStatutoryInvoiceIssuer
{
    public function __construct(
        private readonly StatutoryInvoiceService $invoices,
    ) {}

    public function issueAfterWorkflowCommit(Order $order, ?User $actor = null): void
    {
        try {
            $this->invoices->issueFromSupportOrder($order, $actor);
        } catch (Throwable $exception) {
            Log::warning('service_statutory_invoice.workflow_issue_failed', [
                'order_pk' => $order->id,
                'order_id' => $order->order_id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
