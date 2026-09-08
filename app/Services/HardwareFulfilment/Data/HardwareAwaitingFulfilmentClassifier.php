<?php

namespace App\Services\HardwareFulfilment\Data;

use App\Enums\HardwareAwaitingFulfilmentReason;
use App\Models\Order;
use App\Services\HardwareFulfilment\HardwareFulfilmentEligibility;

final class HardwareAwaitingFulfilmentClassifier
{
    public static function reason(Order $order): HardwareAwaitingFulfilmentReason
    {
        $sourceId = strtoupper(trim((string) $order->order_id));

        if (str_starts_with($sourceId, 'RIN')) {
            return HardwareAwaitingFulfilmentReason::Rin;
        }

        if (HardwareFulfilmentEligibility::isFrozenSourceId($sourceId)) {
            return HardwareAwaitingFulfilmentReason::Frozen;
        }

        if (HardwareFulfilmentEligibility::isHoldSourceId($sourceId)) {
            return HardwareAwaitingFulfilmentReason::Hold;
        }

        if (HardwareFulfilmentEligibility::isBlockedUntilAuthorized($sourceId)) {
            return HardwareAwaitingFulfilmentReason::Blocked;
        }

        if (! $order->isCashfreeVerified()) {
            return HardwareAwaitingFulfilmentReason::Unpaid;
        }

        if ($order->isSerialLocked() || $order->isTransactionLocked()) {
            return HardwareAwaitingFulfilmentReason::DeskAlreadyCompleted;
        }

        if (! HardwareFulfilmentEligibility::isOnOrAfterCutoff($order->created_at)) {
            return HardwareAwaitingFulfilmentReason::PreCutoff;
        }

        return HardwareAwaitingFulfilmentReason::ReviewCandidate;
    }
}
