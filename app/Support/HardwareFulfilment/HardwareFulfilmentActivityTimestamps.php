<?php

namespace App\Support\HardwareFulfilment;

use App\Models\HardwareFulfilment;
use App\Models\Order;
use App\Services\HardwareFulfilment\HardwareFulfilmentEligibility;
use Illuminate\Support\Carbon;

/**
 * IST last-action display timestamps for Hardware dashboard rows.
 * Read-only. Does not mutate fulfilment state.
 */
final class HardwareFulfilmentActivityTimestamps
{
    public static function lastActionIstForOrder(Order $order): string
    {
        return self::formatIst($order->updated_at ?? $order->created_at);
    }

    public static function lastActionIstForFulfilment(HardwareFulfilment $fulfilment): string
    {
        $candidates = [
            $fulfilment->synced_at,
            $fulfilment->shipped_at,
            $fulfilment->awb_assigned_at,
            $fulfilment->shipment_created_at,
            $fulfilment->invoice_issued_at,
            $fulfilment->serials_allocated_at,
            $fulfilment->ready_at,
            $fulfilment->ingested_at,
            $fulfilment->updated_at,
            $fulfilment->created_at,
        ];

        $latest = null;
        foreach ($candidates as $candidate) {
            if (! $candidate instanceof Carbon) {
                continue;
            }
            if ($latest === null || $candidate->gt($latest)) {
                $latest = $candidate;
            }
        }

        return self::formatIst($latest);
    }

    private static function formatIst(mixed $value): string
    {
        if (! $value instanceof Carbon) {
            return '—';
        }

        return $value->copy()
            ->timezone(HardwareFulfilmentEligibility::CUTOFF_TIMEZONE)
            ->format('Y-m-d H:i');
    }
}
