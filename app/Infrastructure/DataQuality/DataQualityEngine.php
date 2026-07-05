<?php

namespace App\Infrastructure\DataQuality;

use App\Models\AuditLog;
use App\Models\Order;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use App\Services\ServiceReferenceIntegrityService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Reusable data quality metrics for orders.
 *
 * Future modules (dashboards, reports, alerts) should consume these metrics
 * instead of duplicating ad-hoc queries.
 */
class DataQualityEngine
{
    public function __construct(
        private readonly RadiumBoxOrderEnrichmentSyncStore $syncStore,
        private readonly ServiceReferenceIntegrityService $serviceReferenceIntegrityService,
    ) {}

    public function missingSerial(): DataQualityMetricResult
    {
        $orderIds = $this->baseQuery()
            ->where(function (Builder $query): void {
                $query->whereNull('serial_number')
                    ->orWhere('serial_number', '');
            })
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return new DataQualityMetricResult(
            metric: DataQualityMetric::MissingSerial,
            count: count($orderIds),
            orderIds: $orderIds,
        );
    }

    public function missingModel(): DataQualityMetricResult
    {
        $orderIds = $this->baseQuery()
            ->whereNull('device_model_id')
            ->where(function (Builder $query): void {
                $query->whereNull('device_model')
                    ->orWhere('device_model', '');
            })
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return new DataQualityMetricResult(
            metric: DataQualityMetric::MissingModel,
            count: count($orderIds),
            orderIds: $orderIds,
        );
    }

    public function missingWarranty(): DataQualityMetricResult
    {
        $orderIds = $this->baseQuery()
            ->get(['id'])
            ->filter(fn (Order $order): bool => ! $this->hasWarranty($order->id))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return new DataQualityMetricResult(
            metric: DataQualityMetric::MissingWarranty,
            count: count($orderIds),
            orderIds: $orderIds,
        );
    }

    public function missingActivation(): DataQualityMetricResult
    {
        $orderIds = $this->baseQuery()
            ->where(function (Builder $query): void {
                $query->whereNull('transaction_id')
                    ->orWhere('transaction_id', '');
            })
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return new DataQualityMetricResult(
            metric: DataQualityMetric::MissingActivation,
            count: count($orderIds),
            orderIds: $orderIds,
        );
    }

    public function missingCustomerContact(): DataQualityMetricResult
    {
        $orderIds = $this->baseQuery()
            ->where(function (Builder $query): void {
                $query->where(function (Builder $inner): void {
                    $inner->whereNull('customer_name')
                        ->orWhere('customer_name', '');
                })->where(function (Builder $inner): void {
                    $inner->whereNull('customer_email')
                        ->orWhere('customer_email', '');
                })->where(function (Builder $inner): void {
                    $inner->whereNull('customer_phone')
                        ->orWhere('customer_phone', '');
                });
            })
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return new DataQualityMetricResult(
            metric: DataQualityMetric::MissingCustomerContact,
            count: count($orderIds),
            orderIds: $orderIds,
        );
    }

    /**
     * @return list<DuplicateSerialGroup>
     */
    public function duplicateSerials(): array
    {
        return Order::query()
            ->whereNotNull('serial_number')
            ->where('serial_number', '!=', '')
            ->get(['id', 'serial_number'])
            ->groupBy('serial_number')
            ->filter(fn (Collection $orders): bool => $orders->count() > 1)
            ->map(fn (Collection $orders, string $serialNumber): DuplicateSerialGroup => new DuplicateSerialGroup(
                serialNumber: $serialNumber,
                orderIds: $orders->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
            ))
            ->values()
            ->all();
    }

    public function duplicateSerial(): DataQualityMetricResult
    {
        $groups = $this->duplicateSerials();
        $orderIds = collect($groups)
            ->flatMap(fn (DuplicateSerialGroup $group): array => $group->orderIds)
            ->unique()
            ->values()
            ->all();

        return new DataQualityMetricResult(
            metric: DataQualityMetric::DuplicateSerial,
            count: count($orderIds),
            orderIds: $orderIds,
        );
    }

    public function unverifiedCompletedCases(): DataQualityMetricResult
    {
        $verifiedOrderIds = AuditLog::query()
            ->where('event', 'legacy.verification_completed')
            ->where('auditable_type', (new Order)->getMorphClass())
            ->pluck('auditable_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $orderIds = Order::query()
            ->whereNotNull('transaction_id')
            ->where('transaction_id', '!=', '')
            ->where(function (Builder $query): void {
                $query->whereNull('cashfree_payment_id')
                    ->orWhere('cashfree_payment_id', '');
            })
            ->when($verifiedOrderIds !== [], fn (Builder $query) => $query->whereNotIn('id', $verifiedOrderIds))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return new DataQualityMetricResult(
            metric: DataQualityMetric::UnverifiedCompletedCase,
            count: count($orderIds),
            orderIds: $orderIds,
        );
    }

    public function duplicateServiceReferences(): DataQualityMetricResult
    {
        $groups = $this->serviceReferenceIntegrityService->sharedReferenceGroups();
        $orderIds = collect($groups)
            ->flatMap(fn (array $group): array => $group['order_ids'])
            ->unique()
            ->values()
            ->all();

        return new DataQualityMetricResult(
            metric: DataQualityMetric::DuplicateServiceReference,
            count: count($orderIds),
            orderIds: $orderIds,
        );
    }

    public function manualInquiryRecords(): DataQualityMetricResult
    {
        $orderIds = Order::query()
            ->where('order_id', 'like', 'INQ-%')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return new DataQualityMetricResult(
            metric: DataQualityMetric::ManualInquiryRecord,
            count: count($orderIds),
            orderIds: $orderIds,
        );
    }

    /**
     * @return array<string, DataQualityMetricResult>
     */
    public function allMetrics(): array
    {
        return [
            DataQualityMetric::MissingSerial->value => $this->missingSerial(),
            DataQualityMetric::MissingModel->value => $this->missingModel(),
            DataQualityMetric::MissingWarranty->value => $this->missingWarranty(),
            DataQualityMetric::MissingActivation->value => $this->missingActivation(),
            DataQualityMetric::MissingCustomerContact->value => $this->missingCustomerContact(),
            DataQualityMetric::DuplicateSerial->value => $this->duplicateSerial(),
            DataQualityMetric::UnverifiedCompletedCase->value => $this->unverifiedCompletedCases(),
            DataQualityMetric::DuplicateServiceReference->value => $this->duplicateServiceReferences(),
            DataQualityMetric::ManualInquiryRecord->value => $this->manualInquiryRecords(),
        ];
    }

    /**
     * @return Builder<Order>
     */
    private function baseQuery(): Builder
    {
        return Order::query();
    }

    private function hasWarranty(int $orderId): bool
    {
        $metadata = $this->syncStore->metadata($orderId);

        if (! is_array($metadata)) {
            return false;
        }

        $warranty = $metadata['warranty'] ?? null;

        return is_string($warranty) && $warranty !== '';
    }
}
