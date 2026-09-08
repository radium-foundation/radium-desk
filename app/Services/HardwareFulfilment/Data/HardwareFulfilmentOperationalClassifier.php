<?php

namespace App\Services\HardwareFulfilment\Data;

use App\Enums\HardwareAwaitingFulfilmentReason;
use App\Enums\HardwareFulfilmentOperationalStage;
use App\Enums\HardwareFulfilmentState;
use App\Enums\HardwareOperationsSection;
use App\Models\HardwareFulfilment;
use App\Models\Order;
use App\Services\HardwareFulfilment\HardwareFulfilmentEligibility;

final class HardwareFulfilmentOperationalClassifier
{
    public const START_FULFILMENT_BLOCKER = 'Isolated ingest requires a verified Box handoff payload. Desk does not invent one from the support order.';

    public function fromAwaiting(Order $order): HardwareFulfilmentOperationalRow
    {
        $reason = HardwareAwaitingFulfilmentClassifier::reason($order);
        $paid = $order->isCashfreeVerified();
        $created = $order->created_at?->copy()->timezone(HardwareFulfilmentEligibility::CUTOFF_TIMEZONE);
        $blocked = $reason !== HardwareAwaitingFulfilmentReason::ReviewCandidate;
        $stage = $blocked
            ? HardwareFulfilmentOperationalStage::BlockedReview
            : HardwareFulfilmentOperationalStage::AwaitingFulfilment;
        $source = $this->sourcePrefix((string) $order->order_id);
        $nextUrl = route('dashboard.orders.customer-360', $order);

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
            nextAction: $blocked ? $reason->label() : 'Review & Start',
            nextUrl: $nextUrl,
            blocker: $blocked
                ? $reason->operatorNote()
                : self::START_FULFILMENT_BLOCKER,
            fulfilmentId: null,
            supportOrderId: (int) $order->id,
            hasFulfilment: false,
            source: $source,
            section: HardwareOperationsSection::fromStage($stage),
            nextAnchor: null,
            mutatingAction: false,
        );
    }

    public function fromRin(Order $order): HardwareFulfilmentOperationalRow
    {
        $created = $order->created_at?->copy()->timezone(HardwareFulfilmentEligibility::CUTOFF_TIMEZONE);
        $paid = $order->isCashfreeVerified();

        return new HardwareFulfilmentOperationalRow(
            sourceId: (string) $order->order_id,
            orderDateIst: $created?->format('Y-m-d H:i') ?? '—',
            customer: trim((string) ($order->customer_name ?? '')) ?: '—',
            product: trim((string) ($order->product_name ?? '')) ?: '—',
            sku: '—',
            quantity: '—',
            payment: $paid ? 'Paid' : 'Unpaid',
            fulfilmentStatus: 'None',
            serialStatus: 'Not allocated',
            invoiceStatus: 'None',
            shipmentStatus: 'None',
            awbStatus: 'None',
            stage: HardwareFulfilmentOperationalStage::BlockedReview,
            nextAction: 'Blocked — RIN mapping required',
            nextUrl: route('dashboard.orders.customer-360', $order),
            blocker: HardwareAwaitingFulfilmentReason::Rin->operatorNote(),
            fulfilmentId: null,
            supportOrderId: (int) $order->id,
            hasFulfilment: false,
            source: 'RIN',
            section: HardwareOperationsSection::Exceptions,
            nextAnchor: null,
            mutatingAction: false,
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
        $ownerBlocked = HardwareFulfilmentEligibility::isFrozenSourceId($sourceId)
            || HardwareFulfilmentEligibility::isHoldSourceId($sourceId)
            || HardwareFulfilmentEligibility::isBlockedUntilAuthorized($sourceId);
        $providerError = filled($ready->providerRejection);

        [$stage, $nextAction, $anchor] = $this->stageAndAction($fulfilment, $ready, $ownerBlocked);
        $section = HardwareOperationsSection::fromStage($stage, $providerError && ! $ownerBlocked);
        if ($providerError && ! $ownerBlocked) {
            $stage = HardwareFulfilmentOperationalStage::BlockedReview;
            $nextAction = 'Provider error';
            $anchor = null;
            $section = HardwareOperationsSection::Exceptions;
        }

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
            blocker: $ownerBlocked
                ? 'Owner-blocked. Do not advance from this queue.'
                : ($ready->providerRejection ?: ($ready->blockers[0] ?? null)),
            fulfilmentId: (int) $fulfilment->id,
            supportOrderId: $fulfilment->support_order_id !== null ? (int) $fulfilment->support_order_id : null,
            hasFulfilment: true,
            source: $this->sourcePrefix($sourceId),
            section: $section,
            nextAnchor: $anchor,
            mutatingAction: ! $ownerBlocked && ! $providerError && $stage !== HardwareFulfilmentOperationalStage::Completed,
        );
    }

    /**
     * @return array{0: HardwareFulfilmentOperationalStage, 1: string, 2: string|null}
     */
    private function stageAndAction(
        HardwareFulfilment $fulfilment,
        HardwareShipmentReadiness $ready,
        bool $blocked,
    ): array {
        if ($blocked) {
            return [HardwareFulfilmentOperationalStage::BlockedReview, 'Blocked / Review Required', null];
        }

        if (in_array($fulfilment->state, [HardwareFulfilmentState::Shipped, HardwareFulfilmentState::Synced], true)) {
            return [HardwareFulfilmentOperationalStage::Completed, 'Completed', null];
        }

        if ($ready->serials === []) {
            return [HardwareFulfilmentOperationalStage::AwaitingSerial, 'Allocate Serial', 'hardware-serial-allocate-form'];
        }

        if ($ready->invoice === null || $ready->invoice === '') {
            return [HardwareFulfilmentOperationalStage::AwaitingInvoice, 'Issue Invoice', 'hardware-invoice'];
        }

        if (! $ready->alreadyCreated) {
            $label = $ready->canFetchCourierOptions && $ready->selectedCourierId === null
                ? 'Get Courier Options'
                : $ready->actionLabel;
            $anchor = $ready->canFetchCourierOptions && $ready->selectedCourierId === null
                ? 'hardware-courier'
                : 'hardware-shipment-create';

            return [HardwareFulfilmentOperationalStage::ReadyForShipment, $label, $anchor];
        }

        if (! filled($ready->awb)) {
            return [HardwareFulfilmentOperationalStage::AwbPending, 'Assign AWB', 'hardware-awb'];
        }

        if ($ready->labelUrl === null || ! $ready->packageLabelAppliedRecorded) {
            if ($ready->labelUrl === null) {
                return [HardwareFulfilmentOperationalStage::LabelPackingPending, 'Print Label', 'hardware-label'];
            }

            return [HardwareFulfilmentOperationalStage::LabelPackingPending, 'Record Packing', 'hardware-package-evidence'];
        }

        if ($ready->pickupStatus === 'Not requested') {
            return [HardwareFulfilmentOperationalStage::PickupManifestPending, 'Request Pickup', 'hardware-manifest-pickup'];
        }

        if ($ready->manifestStatus === 'Not generated') {
            return [HardwareFulfilmentOperationalStage::PickupManifestPending, 'Generate Manifest', 'hardware-manifest-pickup'];
        }

        if ($ready->readyForPickup) {
            return [HardwareFulfilmentOperationalStage::ReadyForPickup, 'Ready for Pickup', 'hardware-manifest-pickup'];
        }

        if ($ready->alreadyCreated && filled($ready->awb)) {
            return [HardwareFulfilmentOperationalStage::PickupManifestPending, 'Ready for Pickup', 'hardware-manifest-pickup'];
        }

        return [HardwareFulfilmentOperationalStage::ShipmentCreated, 'Open fulfilment', null];
    }

    private function sourcePrefix(string $sourceId): string
    {
        $normalized = strtoupper(trim($sourceId));
        if (str_starts_with($normalized, 'RIN')) {
            return 'RIN';
        }

        return 'RDE';
    }
}
