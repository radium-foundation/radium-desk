<?php

namespace App\Services\HardwareFulfilment;

use App\Enums\HardwareFulfilmentState;
use App\Models\HardwareFulfilment;
use Illuminate\Database\Eloquent\Builder;

/**
 * Open Fulfilments tab: in-flight hardware fulfilments (not shipped/synced).
 */
final class HardwareOpenFulfilmentQueue
{
    public const FILTER_ALL = '';

    public const FILTER_READY = 'ready';

    public const FILTER_NOT_CREATED = 'not_created';

    public const FILTER_CREATED = 'created';

    public const FILTER_AWB = 'awb';

    /**
     * @return list<string>
     */
    public static function shipmentStatusFilterKeys(): array
    {
        return [
            self::FILTER_ALL,
            self::FILTER_READY,
            self::FILTER_NOT_CREATED,
            self::FILTER_CREATED,
            self::FILTER_AWB,
        ];
    }

    public function baseQuery(): Builder
    {
        return HardwareFulfilment::query()->whereIn('state', [
            HardwareFulfilmentState::Ingested,
            HardwareFulfilmentState::ReadyForFulfilment,
            HardwareFulfilmentState::SerialsAllocated,
            HardwareFulfilmentState::InvoiceIssued,
            HardwareFulfilmentState::ShipmentCreated,
            HardwareFulfilmentState::AwbAssigned,
        ]);
    }

    public function applySharedFilters(
        Builder $query,
        string $order,
        string $serial,
        ?int $branchId,
    ): Builder {
        if ($order !== '') {
            $query->where(function ($inner) use ($order): void {
                $inner->where('source_id', 'like', '%'.$order.'%')
                    ->orWhereHas('commerceOrder', function ($commerce) use ($order): void {
                        $commerce->where('order_no', 'like', '%'.$order.'%');
                    });
            });
        }

        if ($serial !== '') {
            $query->whereHas('serials', function ($rows) use ($serial): void {
                $rows->where('serial_number', 'like', '%'.$serial.'%');
            });
        }

        if ($branchId !== null) {
            $query->where('fulfilment_branch_id', $branchId);
        }

        return $query;
    }

    public function applyShipmentStatusFilter(Builder $query, string $shipmentStatus): Builder
    {
        if ($shipmentStatus === self::FILTER_NOT_CREATED) {
            return $query->whereNull('shipment_id');
        }

        if ($shipmentStatus === self::FILTER_CREATED) {
            return $query->whereNotNull('shipment_id')->whereNull('awb');
        }

        if ($shipmentStatus === self::FILTER_AWB) {
            return $query->whereNotNull('awb');
        }

        if ($shipmentStatus === self::FILTER_READY) {
            return $query->where('state', HardwareFulfilmentState::InvoiceIssued)
                ->whereNull('shipment_id');
        }

        return $query;
    }

    public function applyStateFilter(Builder $query, string $state): Builder
    {
        if ($state === '') {
            return $query;
        }

        $query->where('state', $state);

        return $query;
    }

    /**
     * Counts for quick-filter chips (excludes fulfilment-state filter; respects order/serial/branch).
     *
     * @return array<string, int>
     */
    public function shipmentStatusCounts(string $order, string $serial, ?int $branchId, string $state): array
    {
        $counts = [];

        foreach (self::shipmentStatusFilterKeys() as $key) {
            $query = $this->applySharedFilters($this->baseQuery(), $order, $serial, $branchId);
            $query = $this->applyStateFilter($query, $state);
            if ($key !== self::FILTER_ALL) {
                $query = $this->applyShipmentStatusFilter($query, $key);
            }
            $counts[$key] = (int) $query->count();
        }

        return $counts;
    }
}
