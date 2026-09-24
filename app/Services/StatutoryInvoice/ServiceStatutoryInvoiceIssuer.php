<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\StatutoryInvoice\ServiceStatutoryInvoiceMintTrigger;
use App\Models\Order;
use App\Models\User;

/**
 * After-commit service-invoice trigger. Reference assign, waiting auto-close,
 * and operator/generic case close all share one commerce-order identity.
 * WhiteBooks GENERATE is not called here.
 *
 * Fail-closed eligibility / missing commerce rows are logged and enqueued for
 * durable retry; they do not roll back the already-committed workflow action.
 */
final class ServiceStatutoryInvoiceIssuer
{
    public function __construct(
        private readonly ServiceStatutoryInvoiceIssuanceCoordinator $coordinator,
    ) {}

    public function issueAfterWorkflowCommit(
        Order $order,
        ?User $actor = null,
        ServiceStatutoryInvoiceMintTrigger $trigger = ServiceStatutoryInvoiceMintTrigger::ServiceReferenceCompleted,
    ): void {
        $this->coordinator->attempt($order, $actor, $trigger);
    }
}
