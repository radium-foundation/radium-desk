<?php

namespace App\Support\StatutoryInvoice;

use App\Enums\StatutoryInvoiceSourceType;
use App\Models\Incident;
use App\Models\StatutoryInvoice;
use App\Models\User;

final class StatutoryInvoiceAccess
{
    public static function allowsView(?User $user, Incident $incident, StatutoryInvoice $invoice): bool
    {
        if ($user === null || $user->cannot('view', $incident)) {
            return false;
        }

        return self::belongsToIncident($invoice, $incident);
    }

    public static function belongsToIncident(StatutoryInvoice $invoice, Incident $incident): bool
    {
        $order = $incident->order;
        if ($order === null) {
            return false;
        }

        if ($invoice->support_order_id !== null && (int) $invoice->support_order_id === (int) $order->id) {
            return true;
        }

        $sourceId = trim((string) $order->order_id);
        if ($sourceId === '') {
            return false;
        }

        if ((string) $invoice->source_type !== StatutoryInvoiceSourceType::CommerceOrder->value) {
            return false;
        }

        return (string) $invoice->source_id === $sourceId
            || (string) $invoice->source_order_id === $sourceId;
    }
}
