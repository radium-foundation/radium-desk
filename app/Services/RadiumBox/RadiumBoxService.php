<?php

namespace App\Services\RadiumBox;

use App\Models\Order;

class RadiumBoxService
{
    public function __construct(
        private readonly RadiumBoxClient $client,
    ) {}

    public function enrichOrderForWorkspace(Order $order): Order
    {
        if (! $this->needsEnrichment($order)) {
            return $order;
        }

        $enrichment = $this->client->fetchOrderEnrichment($order->order_id);

        if ($enrichment === null || ! $enrichment->hasData()) {
            return $order;
        }

        $updates = $this->buildUpdates($order, $enrichment);

        if ($updates === []) {
            return $order;
        }

        $order->update($updates);

        return $order->fresh();
    }

    private function needsEnrichment(Order $order): bool
    {
        return ! $order->isSerialLocked() || ! filled($order->device_model);
    }

    /**
     * @return array<string, string>
     */
    private function buildUpdates(Order $order, RadiumBoxOrderEnrichment $enrichment): array
    {
        $updates = [];

        if (! $order->isSerialLocked() && filled($enrichment->serialNumber)) {
            $updates['serial_number'] = $enrichment->serialNumber;
        }

        if (! filled($order->device_model) && filled($enrichment->deviceModel)) {
            $updates['device_model'] = $enrichment->deviceModel;
        }

        return $updates;
    }
}
