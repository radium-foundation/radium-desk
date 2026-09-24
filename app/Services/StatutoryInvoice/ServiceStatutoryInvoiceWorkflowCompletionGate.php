<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\IncidentStatus;
use App\Models\Order;

/**
 * Determines whether a support order has reached a workflow point where
 * statutory invoice issuance is expected (reference/completion or case closure).
 */
final class ServiceStatutoryInvoiceWorkflowCompletionGate
{
    public function supportOrderHasCompletedWorkflow(Order $order): bool
    {
        if ($order->completed_at !== null) {
            return true;
        }

        $transactionId = trim((string) ($order->transaction_id ?? ''));
        if ($transactionId !== '') {
            return true;
        }

        $order->loadMissing('incidents');

        foreach ($order->incidents as $incident) {
            if ($incident->status === IncidentStatus::Closed) {
                return true;
            }
        }

        return false;
    }
}
