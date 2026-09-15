<?php

namespace App\Support\HardwareFulfilment;

use App\Models\HardwareFulfilment;
use App\Models\Order;
use App\Services\HardwareFulfilment\HardwareFulfilmentEligibility;
use Illuminate\Support\Carbon;

/**
 * IST activity timestamps from fields already loaded on the row source.
 * No extra queries.
 */
final class HardwareFulfilmentActivityTimestamps
{
    public static function lastActionIstForOrder(Order $order): string
    {
        $at = $order->updated_at ?? $order->created_at;

        return self::formatIst($at);
    }

    public static function lastActionIstForFulfilment(HardwareFulfilment $fulfilment): string
    {
        $candidates = array_filter([
            $fulfilment->ready_for_pickup_at,
            $fulfilment->shipped_at,
            $fulfilment->awb_assigned_at,
            $fulfilment->shipment_created_at,
            $fulfilment->invoice_issued_at,
            $fulfilment->serials_allocated_at,
            $fulfilment->ready_at,
            $fulfilment->ingested_at,
            $fulfilment->updated_at,
        ], static fn ($value): bool => $value !== null);

        if ($candidates === []) {
            return '—';
        }

        return self::formatIst(collect($candidates)->max());
    }

    private static function formatIst(?Carbon $at): string
    {
        if ($at === null) {
            return '—';
        }

        return $at->copy()
            ->timezone(HardwareFulfilmentEligibility::CUTOFF_TIMEZONE)
            ->format('Y-m-d H:i');
    }
}
