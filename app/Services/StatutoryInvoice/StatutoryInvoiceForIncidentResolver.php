<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\StatutoryInvoiceSourceType;
use App\Models\Incident;
use App\Models\Order;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Support\StatutoryInvoice\StatutoryInvoiceAccess;
use App\Support\StatutoryInvoice\StatutoryInvoiceNumber;
use Illuminate\Support\Collection;

class StatutoryInvoiceForIncidentResolver
{
    /**
     * @return Collection<int, StatutoryInvoice>
     */
    public function forIncident(Incident $incident): Collection
    {
        $order = $incident->order;
        if (! $order instanceof Order) {
            return collect();
        }

        $sourceId = trim((string) $order->order_id);

        return StatutoryInvoice::query()
            ->with('eInvoiceRecord')
            ->where(function ($query) use ($order, $sourceId): void {
                $query->where('support_order_id', $order->id);

                if ($sourceId !== '') {
                    $query->orWhere(function ($commerce) use ($sourceId): void {
                        $commerce->where('source_type', StatutoryInvoiceSourceType::CommerceOrder->value)
                            ->where(function ($identity) use ($sourceId): void {
                                $identity->where('source_id', $sourceId)
                                    ->orWhere('source_order_id', $sourceId);
                            });
                    });
                }
            })
            ->orderByDesc('id')
            ->limit(10)
            ->get();
    }

    public function findAuthorized(Incident $incident, StatutoryInvoice $invoice, User $user): StatutoryInvoice
    {
        abort_unless(StatutoryInvoiceAccess::allowsView($user, $incident, $invoice), 404);

        return $invoice;
    }

    /**
     * Indexed unique lookup by invoice number, then the linked service case.
     */
    public function incidentForInvoiceNumber(string $identifier, User $user): ?Incident
    {
        if (! StatutoryInvoiceNumber::looksLike($identifier)) {
            return null;
        }

        $invoice = StatutoryInvoice::query()
            ->where('invoice_number', StatutoryInvoiceNumber::normalize($identifier))
            ->first();

        if ($invoice === null) {
            return null;
        }

        $order = $this->orderForInvoice($invoice);
        if ($order === null) {
            return null;
        }

        $incident = Incident::query()
            ->where('order_id', $order->id)
            ->latest('id')
            ->first();

        if (! $incident instanceof Incident || $user->cannot('view', $incident)) {
            return null;
        }

        $incident->setRelation('order', $order);

        if (! StatutoryInvoiceAccess::belongsToIncident($invoice, $incident)) {
            return null;
        }

        return $incident;
    }

    private function orderForInvoice(StatutoryInvoice $invoice): ?Order
    {
        if ($invoice->support_order_id !== null) {
            $order = Order::query()->find($invoice->support_order_id);
            if ($order instanceof Order) {
                return $order;
            }
        }

        $sourceId = trim((string) $invoice->source_id);
        if ($sourceId === '' || (string) $invoice->source_type !== StatutoryInvoiceSourceType::CommerceOrder->value) {
            return null;
        }

        return Order::query()
            ->where('order_id', $sourceId)
            ->orderByDesc('id')
            ->first();
    }
}
