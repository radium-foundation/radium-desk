<?php

namespace App\Services\HardwareFulfilment;

use App\Models\CommerceOrder;
use Illuminate\Validation\ValidationException;

final class HardwareFulfilmentCancellationGuard
{
    public static function assertFulfilmentNotBlocked(CommerceOrder $order): void
    {
        $fresh = $order->fresh() ?? $order;

        if ($fresh->hardware_fulfilment_blocked_at !== null) {
            throw ValidationException::withMessages([
                'commerce_order' => 'Hardware fulfilment is blocked by a committed pre-shipment cancellation.',
                'error_code' => 'hardware_fulfilment_blocked_preship_cancel',
            ]);
        }
    }

    public static function isBlocked(CommerceOrder $order): bool
    {
        $fresh = $order->fresh() ?? $order;

        return $fresh->hardware_fulfilment_blocked_at !== null;
    }
}
