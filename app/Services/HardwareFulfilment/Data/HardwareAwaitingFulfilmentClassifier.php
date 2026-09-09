<?php

namespace App\Services\HardwareFulfilment\Data;

use App\Enums\HardwareAwaitingFulfilmentReason;
use App\Models\CommerceOrder;
use App\Models\Order;
use App\Services\HardwareFulfilment\HardwareFulfilmentEligibility;
use App\Services\HardwareFulfilment\HardwareSkuMapService;

final class HardwareAwaitingFulfilmentClassifier
{
    public static function reason(Order $order, ?CommerceOrder $commerce = null): HardwareAwaitingFulfilmentReason
    {
        $sourceId = strtoupper(trim((string) $order->order_id));

        if (str_starts_with($sourceId, 'RIN')) {
            return HardwareAwaitingFulfilmentReason::Rin;
        }

        if (HardwareFulfilmentEligibility::isHoldSourceId($sourceId)) {
            return HardwareAwaitingFulfilmentReason::Hold;
        }

        if (HardwareFulfilmentEligibility::isBlockedUntilAuthorized($sourceId)) {
            return HardwareAwaitingFulfilmentReason::Blocked;
        }

        if (HardwareFulfilmentEligibility::isFrozenForFulfilment($sourceId, $commerce)) {
            return HardwareAwaitingFulfilmentReason::Frozen;
        }

        if (! $order->isCashfreeVerified()) {
            return HardwareAwaitingFulfilmentReason::Unpaid;
        }

        if (self::isRecoveredCommerce($sourceId, $commerce)) {
            return HardwareAwaitingFulfilmentReason::RecoveredCommerce;
        }

        if ($order->isSerialLocked() || $order->isTransactionLocked()) {
            return HardwareAwaitingFulfilmentReason::DeskAlreadyCompleted;
        }

        if (! HardwareFulfilmentEligibility::isOnOrAfterCutoff($order->created_at)) {
            return HardwareAwaitingFulfilmentReason::PreCutoff;
        }

        if (self::isSplitTender($commerce)) {
            return HardwareAwaitingFulfilmentReason::SplitTender;
        }

        if ($commerce === null || ! HardwareFulfilmentEligibility::hasHardwareLines($commerce)) {
            return HardwareAwaitingFulfilmentReason::AwaitingHandoff;
        }

        if (self::isMissingProductMapping($commerce)) {
            return HardwareAwaitingFulfilmentReason::ProductMappingRequired;
        }

        return HardwareAwaitingFulfilmentReason::ReviewCandidate;
    }

    private static function isRecoveredCommerce(string $sourceId, ?CommerceOrder $commerce): bool
    {
        if ($commerce === null || ! HardwareFulfilmentEligibility::isFrozenSourceId($sourceId)) {
            return false;
        }

        if (! HardwareFulfilmentEligibility::isPaidCommerceOrder($commerce)) {
            return false;
        }

        $commerce->loadMissing('items');

        return HardwareFulfilmentEligibility::hasHardwareLines($commerce)
            && ! HardwareFulfilmentEligibility::isFrozenForFulfilment($sourceId, $commerce);
    }

    private static function isSplitTender(?CommerceOrder $commerce): bool
    {
        if ($commerce === null) {
            return false;
        }

        return $commerce->wallet_tender_amount !== null
            && (float) $commerce->wallet_tender_amount >= 0.01;
    }

    private static function isMissingProductMapping(CommerceOrder $commerce): bool
    {
        $commerce->loadMissing('items');
        $maps = app(HardwareSkuMapService::class);
        $channel = $commerce->channel;
        foreach ($commerce->items as $item) {
            if (! HardwareFulfilmentEligibility::isPhysicalCommerceItem($item)) {
                continue;
            }
            $modelId = $item->model_id !== null ? (int) $item->model_id : null;
            if ($maps->findProduct($channel, $modelId) === null) {
                return true;
            }
        }

        return false;
    }
}
