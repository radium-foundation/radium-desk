<?php

namespace App\Support\Timeline;

use App\Enums\WaitingReason;

final class TimelineWaitingReasonPresenter
{
    public static function awaitingLabel(WaitingReason $reason): string
    {
        return match ($reason) {
            WaitingReason::SerialNumber => 'Device Serial Number',
            WaitingReason::Photos => 'Device Photos',
            WaitingReason::CustomerApproval => 'Customer Confirmation',
            WaitingReason::Payment => 'Payment',
            WaitingReason::Invoice => 'Invoice',
            WaitingReason::DevicePickup => 'Device Pickup',
            WaitingReason::CustomerNotResponding => 'Customer Response',
            WaitingReason::Other => 'Customer Input',
        };
    }

    public static function contextLine(WaitingReason $reason): string
    {
        return 'Awaiting: '.self::awaitingLabel($reason);
    }
}
