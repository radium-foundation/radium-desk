<?php

namespace App\Services\HistoricalInvoice;

use App\Models\Incident;
use App\Models\Order;
use App\Models\User;

class HistoricalCustomer360Resolver
{
    public function __construct(
        private readonly HistoricalInvoiceLookupService $lookup,
    ) {}

    /**
     * @return array{incident: Incident, order: Order, invoice_number: string}|null
     */
    public function resolve(string $identifier, User $user): ?array
    {
        if (! HistoricalInvoiceLookupService::shouldOfferHistoricalLookup($identifier)) {
            return null;
        }

        $result = $this->lookup->lookup($identifier);
        if (! $result->canReprint() || ! is_string($result->invoiceNumber) || $result->invoiceNumber === '') {
            return null;
        }

        $order = $this->findDeskOrder($result);
        if ($order === null) {
            return null;
        }

        $incident = $order->incidents()->latest('id')->first();
        if (! $incident instanceof Incident) {
            return null;
        }

        if ($user->cannot('view', $incident)) {
            return null;
        }

        return [
            'incident' => $incident,
            'order' => $order,
            'invoice_number' => $result->invoiceNumber,
        ];
    }

    private function findDeskOrder(HistoricalInvoiceResult $result): ?Order
    {
        $orderIds = [];
        foreach ([
            $result->orderId,
            $result->reprint['ordercode'] ?? null,
            $result->reprint['rdorderid'] ?? null,
            $result->reprint['order_id'] ?? null,
        ] as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                $orderIds[] = $candidate;
            }
        }

        $orderIds = array_values(array_unique($orderIds));
        $invoiceNumber = $result->invoiceNumber;

        if ($orderIds === [] && (! is_string($invoiceNumber) || $invoiceNumber === '')) {
            return null;
        }

        return Order::query()
            ->where(function ($query) use ($orderIds, $invoiceNumber): void {
                if ($orderIds !== []) {
                    $query->whereIn('order_id', $orderIds);
                }

                if (is_string($invoiceNumber) && $invoiceNumber !== '') {
                    if ($orderIds !== []) {
                        $query->orWhere('invoice_number', $invoiceNumber);
                    } else {
                        $query->where('invoice_number', $invoiceNumber);
                    }
                }
            })
            ->orderByDesc('id')
            ->first();
    }
}
