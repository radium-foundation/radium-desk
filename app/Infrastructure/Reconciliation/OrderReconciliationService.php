<?php

namespace App\Infrastructure\Reconciliation;

use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Infrastructure\DataQuality\DataQualityEngine;
use App\Models\Order;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use Illuminate\Support\Carbon;

class OrderReconciliationService
{
    public function __construct(
        private readonly DataQualityEngine $dataQualityEngine,
        private readonly RadiumBoxOrderEnrichmentSyncStore $syncStore,
    ) {}

    public function report(): ReconciliationReport
    {
        $orders = Order::query()->get(['id', 'serial_entered_by_user_id', 'device_model_assigned_by_user_id']);

        $missingSerialIds = collect($this->dataQualityEngine->missingSerial()->orderIds);
        $missingModelIds = collect($this->dataQualityEngine->missingModel()->orderIds);

        $awaitingSync = 0;
        $failedSync = 0;
        $synced = 0;
        $manualSerial = 0;
        $manualDeviceModel = 0;

        foreach ($orders as $order) {
            $status = $this->syncStore->status($order->id);

            if ($status === RadiumBoxEnrichmentSyncStatus::Pending) {
                $awaitingSync++;
            } elseif ($status === RadiumBoxEnrichmentSyncStatus::Failed) {
                $failedSync++;
            } elseif ($status === RadiumBoxEnrichmentSyncStatus::Synced) {
                $synced++;
            }

            if ($order->serial_entered_by_user_id !== null) {
                $manualSerial++;
            }

            if ($order->device_model_assigned_by_user_id !== null) {
                $manualDeviceModel++;
            }
        }

        $missingBoth = $missingSerialIds
            ->intersect($missingModelIds)
            ->count();

        return new ReconciliationReport(
            totalOrders: $orders->count(),
            ordersMissingSerial: $missingSerialIds->count(),
            ordersMissingDeviceModel: $missingModelIds->count(),
            ordersMissingBoth: $missingBoth,
            ordersAwaitingSync: $awaitingSync,
            ordersWithFailedSync: $failedSync,
            ordersSuccessfullySynced: $synced,
            ordersUsingManualSerial: $manualSerial,
            ordersUsingManualDeviceModel: $manualDeviceModel,
            generatedAt: now(),
        );
    }

    /**
     * @return list<ReconciliationOrderRow>
     */
    public function orderRows(): array
    {
        return Order::query()
            ->with('deviceModel')
            ->orderBy('order_id')
            ->get()
            ->map(fn (Order $order): ReconciliationOrderRow => $this->buildOrderRow($order))
            ->all();
    }

    private function buildOrderRow(Order $order): ReconciliationOrderRow
    {
        $metadata = $this->syncStore->metadata($order->id);
        $status = $this->syncStore->status($order->id);
        $updatedAt = $this->syncStore->updatedAt($order->id);

        return new ReconciliationOrderRow(
            orderId: $order->order_id,
            customer: $this->resolveCustomer($order),
            serial: filled($order->serial_number) ? $order->serial_number : null,
            model: $order->displayDeviceModelName(),
            syncStatus: $status?->value,
            failureReason: is_array($metadata) ? ($metadata['last_error'] ?? null) : null,
            lastAttempt: $updatedAt !== null ? Carbon::parse($updatedAt) : null,
            manualOverride: $this->resolveManualOverrideLabel($order),
        );
    }

    private function resolveCustomer(Order $order): ?string
    {
        if (filled($order->customer_name)) {
            return $order->customer_name;
        }

        if (filled($order->customer_email)) {
            return $order->customer_email;
        }

        return filled($order->customer_phone) ? $order->customer_phone : null;
    }

    private function resolveManualOverrideLabel(Order $order): ?string
    {
        $parts = [];

        if ($order->serial_entered_by_user_id !== null) {
            $parts[] = 'Serial';
        }

        if ($order->device_model_assigned_by_user_id !== null) {
            $parts[] = 'Device Model';
        }

        return $parts === [] ? null : implode(', ', $parts);
    }
}
