<?php

namespace App\Services\RadiumBox;

use App\Data\EnrichmentPersistenceResult;
use App\Models\Order;
use App\Services\OrderIdentityLifecycleService;
use App\Services\OrderIdentityProtectionService;
use Illuminate\Support\Facades\Log;

class RadiumBoxService
{
    public function __construct(
        private readonly RadiumBoxClient $client,
        private readonly OrderIdentityProtectionService $identityProtection,
        private readonly OrderIdentityLifecycleService $identityLifecycle,
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
     *     persistence: EnrichmentPersistenceResult,
     * }
     */
    public function enrichOrderFromBackgroundSync(Order $order): array
    {
        if (! $this->needsEnrichment($order)) {
            return [
                'applied' => false,
                'enrichment' => null,
                'fetch_result' => new RadiumBoxOrderEnrichmentFetchResult(retriable: false),
                'persistence' => $this->emptyPersistenceResult(),
            ];
        }

        $fetchResult = $this->client->fetchOrderEnrichmentForBackgroundSync($order->order_id);

        if ($fetchResult->errorType === 'disabled') {
            return [
                'applied' => false,
                'enrichment' => null,
                'fetch_result' => $fetchResult,
                'persistence' => $this->emptyPersistenceResult(),
            ];
        }

        if ($fetchResult->retriable) {
            return [
                'applied' => false,
                'enrichment' => null,
                'fetch_result' => $fetchResult,
                'persistence' => $this->emptyPersistenceResult(),
            ];
        }

        $enrichment = $fetchResult->enrichment;

        if ($enrichment === null || ! $enrichment->hasData()) {
            return [
                'applied' => false,
                'enrichment' => $enrichment,
                'fetch_result' => $fetchResult,
                'persistence' => $this->emptyPersistenceResult(),
            ];
        }

        $persistence = $this->persistEnrichment($order, $enrichment);

        return [
            'applied' => $persistence->updated,
            'enrichment' => $enrichment,
            'fetch_result' => $fetchResult,
            'persistence' => $persistence,
        ];
    }

    private function applyEnrichment(Order $order, RadiumBoxOrderEnrichment $enrichment): Order
    {
        $persistence = $this->persistEnrichment($order, $enrichment);

        if (! $persistence->updated) {
            return $order;
        }

        return $order->fresh();
    }

    private function persistEnrichment(Order $order, RadiumBoxOrderEnrichment $enrichment): EnrichmentPersistenceResult
    {
        $updates = $this->buildUpdates($order, $enrichment);

        $serialApplied = array_key_exists('serial_number', $updates);
        $deviceModelApplied = array_key_exists('device_model', $updates);
        $fieldsApplied = array_keys($updates);

        if ($updates === []) {
            return $this->emptyPersistenceResult();
        }

        $order->update($updates);

        $this->identityLifecycle->afterIdentityFieldsChanged(
            order: $order,
            actor: $this->identityLifecycle->resolveActorForOrder($order),
            source: 'radiumbox_enrichment',
            changedFields: $fieldsApplied,
        );

        return new EnrichmentPersistenceResult(
            updated: true,
            fieldsApplied: $fieldsApplied,
            serialApplied: $serialApplied,
            deviceModelApplied: $deviceModelApplied,
            warrantyApplied: false,
            activationYearApplied: false,
            amcApplied: false,
        );
    }

    /**
     * @return array<string, string>
     */
    private function buildUpdates(Order $order, RadiumBoxOrderEnrichment $enrichment): array
    {
        $updates = [];

        if (! $order->isSerialLocked() && filled($enrichment->serialNumber)) {
            $serialNumber = strtoupper(trim($enrichment->serialNumber));

            if ($serialNumber !== '') {
                $existingOwner = $this->findSerialOwner($order, $serialNumber);

                if ($existingOwner !== null) {
                    Log::warning('Duplicate serial prevented.', [
                        'incoming_order_id' => $order->order_id,
                        'incoming_order_db_id' => $order->id,
                        'existing_owner_order_id' => $existingOwner->order_id,
                        'existing_owner_db_id' => $existingOwner->id,
                        'serial_number' => $serialNumber,
                    ]);
                } else {
                    $updates['serial_number'] = $serialNumber;
                }
            }
        }

        if (! $order->hasDeviceModelAssigned() && ! filled($order->device_model) && filled($enrichment->deviceModel)) {
            $updates['device_model'] = $enrichment->deviceModel;
        }

        $updates = [
            ...$updates,
            ...$this->identityProtection->buildExternalIdentityUpdates($order, [
                'customer_name' => $enrichment->customerName,
                'customer_phone' => $enrichment->customerPhone,
                'customer_email' => $enrichment->customerEmail,
            ]),
        ];

        return $updates;
    }

    private function findSerialOwner(Order $order, string $serialNumber): ?Order
    {
        return Order::query()
            ->where('serial_number', $serialNumber)
            ->whereKeyNot($order->id)
            ->first(['id', 'order_id', 'serial_number']);
    }

    private function emptyPersistenceResult(): EnrichmentPersistenceResult
    {
        return new EnrichmentPersistenceResult(
            updated: false,
            fieldsApplied: [],
            serialApplied: false,
            deviceModelApplied: false,
            warrantyApplied: false,
            activationYearApplied: false,
            amcApplied: false,
        );
    }
}
