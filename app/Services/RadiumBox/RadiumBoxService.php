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

        return $this->applyEnrichment($order, $enrichment);
    }

    public function needsEnrichment(Order $order): bool
    {
        return ! $order->isSerialLocked()
            || (! $order->hasDeviceModelAssigned() && ! filled($order->device_model));
    }

    /**
     * @return array{
     *     applied: bool,
     *     enrichment: ?RadiumBoxOrderEnrichment,
     *     fetch_result: RadiumBoxOrderEnrichmentFetchResult,
     * }
     */
    public function enrichOrderFromBackgroundSync(Order $order): array
    {
        if (! $this->needsEnrichment($order)) {
            return [
                'applied' => false,
                'enrichment' => null,
                'fetch_result' => new RadiumBoxOrderEnrichmentFetchResult(retriable: false),
            ];
        }

        $fetchResult = $this->client->fetchOrderEnrichmentForBackgroundSync($order->order_id);

        if ($fetchResult->errorType === 'disabled') {
            return [
                'applied' => false,
                'enrichment' => null,
                'fetch_result' => $fetchResult,
            ];
        }

        if ($fetchResult->retriable) {
            return [
                'applied' => false,
                'enrichment' => null,
                'fetch_result' => $fetchResult,
            ];
        }

        $enrichment = $fetchResult->enrichment;

        if ($enrichment === null || ! $enrichment->hasData()) {
            return [
                'applied' => false,
                'enrichment' => $enrichment,
                'fetch_result' => $fetchResult,
            ];
        }

        $updates = $this->buildUpdates($order, $enrichment);

        if ($updates !== []) {
            $order->update($updates);
        }

        return [
            'applied' => $updates !== [],
            'enrichment' => $enrichment,
            'fetch_result' => $fetchResult,
        ];
    }

    private function applyEnrichment(Order $order, RadiumBoxOrderEnrichment $enrichment): Order
    {
        $updates = $this->buildUpdates($order, $enrichment);

        if ($updates === []) {
            return $order;
        }

        $order->update($updates);

        return $order->fresh();
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

        if (! $order->hasDeviceModelAssigned() && ! filled($order->device_model) && filled($enrichment->deviceModel)) {
            $updates['device_model'] = $enrichment->deviceModel;
        }

        return $updates;
    }
}
