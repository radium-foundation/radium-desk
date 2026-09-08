<?php

namespace App\Services\HardwareFulfilment\Data;

use App\Enums\HardwareAwaitingFulfilmentReason;
use App\Enums\HardwareFulfilmentOperationalStage;
use App\Enums\HardwareFulfilmentState;
use App\Models\HardwareFulfilment;
use App\Models\Order;
use App\Services\HardwareFulfilment\HardwareFulfilmentEligibility;

final class HardwareFulfilmentOperationalClassifier
{
    public function fromAwaiting(Order $order): HardwareFulfilmentOperationalRow
    {
        $reason = HardwareAwaitingFulfilmentClassifier::reason($order);
        $paid = $order->isCashfreeVerified();
        $created = $order->created_at?->copy()->timezone(HardwareFulfilmentEligibility::CUTOFF_TIMEZONE);
        $blocked = $reason !== HardwareAwaitingFulfilmentReason::ReviewCandidate;
        $stage = $blocked
            ? HardwareFulfilmentOperationalStage::BlockedReview
            : HardwareFulfilmentOperationalStage::AwaitingFulfilment;

        return new HardwareFulfilmentOperationalRow(
            sourceId: (string) $order->order_id,
            orderDateIst: $created?->format('Y-m-d H:i') ?? '—',
            customer: trim((string) ($order->customer_name ?? '')) ?: '—',
            product: trim((string) ($order->product_name ?? '')) ?: '—',
            sku: '—',
            quantity: '—',
            payment: $paid ? 'Paid' : 'Unpaid',
            fulfilmentStatus: 'None',
            serialStatus: $order->isSerialLocked() ? 'On support order' : 'Not allocated',
            invoiceStatus: 'None',
            shipmentStatus: 'None',
            awbStatus: 'None',
            stage: $stage,
            nextAction: $blocked ? $reason->label() : 'Review order',
            nextUrl: route('orders.show', $order),
            blocker: $blocked ? $reason->operatorNote() : HardwareAwaitingFulfilmentReason::ReviewCandidate->operatorNote(),
            fulfilmentId: null,
            supportOrderId: (int) $order->id,
            hasFulfilment: false,
        );
    }

    public function fromFulfilment(HardwareFulfilment $fulfilment, HardwareShipmentReadiness $ready): HardwareFulfilmentOperationalRow
    {
        $order = $fulfilment->commerceOrder;
        $date = $order?->ordered_at ?? $order?->paid_at ?? $fulfilment->ingested_at ?? $fulfilment->created_at;
        $ist = $date?->copy()->timezone(HardwareFulfilmentEligibility::CUTOFF_TIMEZONE);
        $item = $order?->items?->first(
            static fn ($row): bool => HardwareFulfilmentEligibility::isPhysicalCommerceItem($row)
        ) ?? $order?->items?->first();
        $sourceId = (string) $fulfilment->source_id;
        $blocked = HardwareFulfilmentEligibility::isFrozenSourceId($sourceId)
            || HardwareFulfilmentEligibility::isHoldSourceId($sourceId)
            || HardwareFulfilmentEligibility::isBlockedUntilAuthorized($sourceId);

        [$stage, $nextAction] = $this->stageAndAction($fulfilment, $ready, $blocked);
        $nextUrl = route('inventory.hardware-fulfilments.show', $fulfilment);

        return new HardwareFulfilmentOperationalRow(
            sourceId: $sourceId,
            orderDateIst: $ist?->format('Y-m-d H:i') ?? '—',
            customer: $ready->customer ?: '—',
            product: $ready->product ?: '—',
            sku: trim((string) ($item?->sku ?: $item?->catalog_sku ?: '')) ?: '—',
            quantity: $ready->quantity !== null ? (string) $ready->quantity : '—',
            payment: $ready->payment,
            fulfilmentStatus: strtoupper(str_replace('_', ' ', $fulfilment->state?->value ?? 'unknown')),
            serialStatus: $ready->serials !== [] ? implode(', ', $ready->serials) : 'Not allocated',
            invoiceStatus: $ready->invoice ?: 'None',
            shipmentStatus: $ready->status,
            awbStatus: $ready->awb ?: 'Not assigned',
            stage: $stage,
            nextAction: $nextAction,
            nextUrl: $nextUrl,
            blocker: $blocked
                ? 'Owner-blocked. Do not advance from this queue.'
                : ($ready->providerRejection ?: ($ready->blockers[0] ?? null)),
            fulfilmentId: (int) $fulfilment->id,
            supportOrderId: $fulfilment->support_order_id !== null ? (int) $fulfilment->support_order_id : null,
            hasFulfilment: true,
        );
    }

    /**
     * @return array{0: HardwareFulfilmentOperationalStage, 1: string}
     */
    private function stageAndAction(
        HardwareFulfilment $fulfilment,
        HardwareShipmentReadiness $ready,
        bool $blocked,
    ): array {
        if ($blocked) {
            return [HardwareFulfilmentOperationalStage::BlockedReview, 'Blocked / Review Required'];
        }

        if (in_array($fulfilment->state, [HardwareFulfilmentState::Shipped, HardwareFulfilmentState::Synced], true)) {
            return [HardwareFulfilmentOperationalStage::Completed, 'Completed'];
        }

        if ($ready->serials === []) {
            return [HardwareFulfilmentOperationalStage::AwaitingSerial, 'Allocate Serial'];
        }

        if ($ready->invoice === null || $ready->invoice === '') {
            return [HardwareFulfilmentOperationalStage::AwaitingInvoice, 'Issue Invoice'];
        }

        if (! $ready->alreadyCreated) {
            return [HardwareFulfilmentOperationalStage::ReadyForShipment, $ready->actionLabel];
        }

        if (! filled($ready->awb)) {
            return [HardwareFulfilmentOperationalStage::AwbPending, 'Assign AWB'];
        }

        if ($ready->labelUrl === null || ! $ready->packageLabelAppliedRecorded) {
            $action = $ready->labelUrl === null ? 'Generate/Print Label' : 'Record Package / Label-Applied Evidence';

            return [HardwareFulfilmentOperationalStage::LabelPackingPending, $action];
        }

        if (! $ready->readyForPickup && $ready->pickupStatus === 'Not requested') {
            return [HardwareFulfilmentOperationalStage::PickupManifestPending, 'Request Pickup'];
        }

        if (! $ready->readyForPickup && $ready->manifestStatus === 'Not generated') {
            return [HardwareFulfilmentOperationalStage::PickupManifestPending, 'Generate Manifest'];
        }

        if ($ready->readyForPickup) {
            return [HardwareFulfilmentOperationalStage::ReadyForPickup, 'Ready for Pickup'];
        }

        if ($ready->alreadyCreated && filled($ready->awb)) {
            return [HardwareFulfilmentOperationalStage::PickupManifestPending, 'Mark Ready for Pickup'];
        }

        return [HardwareFulfilmentOperationalStage::ShipmentCreated, 'Open fulfilment'];
    }
}
