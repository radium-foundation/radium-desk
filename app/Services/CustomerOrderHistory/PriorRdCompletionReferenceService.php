<?php

namespace App\Services\CustomerOrderHistory;

use App\Models\Order;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use App\Support\BusinessOrderId;
use Illuminate\Support\Collection;

class PriorRdCompletionReferenceService
{
    public function __construct(
        private readonly SpokeOrderServiceValidityEvaluator $validityEvaluator,
        private readonly RadiumBoxOrderEnrichmentSyncStore $enrichmentSyncStore,
    ) {}

    /**
     * Reference completion from a valid, non-expired prior RD service order for the same customer.
     *
     * @param  Collection<int, Order>  $serviceOrders
     * @return array<string, mixed>|null
     */
    public function referenceForHighlight(Collection $serviceOrders, ?Order $highlightOrder): ?array
    {
        if ($highlightOrder === null) {
            return null;
        }

        $candidates = $serviceOrders
            ->filter(fn (Order $order): bool => (int) $order->id !== (int) $highlightOrder->id)
            ->filter(fn (Order $order): bool => BusinessOrderId::isRdServiceInService($order->order_id))
            ->sortByDesc(fn (Order $order): array => [$order->created_at?->timestamp ?? 0, $order->id])
            ->values();

        foreach ($candidates as $priorOrder) {
            $metadata = $this->enrichmentSyncStore->metadata($priorOrder->id) ?? [];
            $validity = $this->validityEvaluator->evaluate($priorOrder, $metadata);

            if ($validity['status'] === 'expired' || ! $validity['eligible_for_reference']) {
                continue;
            }

            $serial = $priorOrder->serial_number !== null
                ? strtoupper(trim((string) $priorOrder->serial_number))
                : null;

            if ($serial === '') {
                $serial = null;
            }

            return [
                'source_order_id' => $priorOrder->order_id,
                'source_order_db_id' => $priorOrder->id,
                'serial_number' => $serial,
                'product_service' => $priorOrder->displayDeviceModelName(),
                'service_history' => $priorOrder->service_history,
                'warranty' => $validity['warranty'],
                'amc' => $validity['amc'],
                'validity_status' => $validity['status'],
                'validity_label' => $validity['label'],
                'reference_only' => true,
            ];
        }

        return null;
    }
}
