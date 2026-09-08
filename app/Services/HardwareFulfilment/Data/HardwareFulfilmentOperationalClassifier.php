<?php

namespace App\Services\HardwareFulfilment\Data;

use App\Enums\HardwareAwaitingFulfilmentReason;
use App\Enums\HardwareFulfilmentOperationalStage;
use App\Enums\HardwareFulfilmentState;
use App\Enums\HardwareOperationsSection;
use App\Models\CommerceOrder;
use App\Models\HardwareFulfilment;
use App\Models\Order;
use App\Services\HardwareFulfilment\HardwareFulfilmentEligibility;

final class HardwareFulfilmentOperationalClassifier
{
    public const START_FULFILMENT_BLOCKER = 'Isolated ingest requires a verified Box handoff payload. Desk does not invent one from the support order.';

    public function fromAwaiting(Order $order, ?CommerceOrder $commerce = null): HardwareFulfilmentOperationalRow
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
        $catalog = HardwareFulfilmentProductLines::resolve($commerce, $order);

        return new HardwareFulfilmentOperationalRow(
            sourceId: (string) $order->order_id,
            orderDateIst: $created?->format('Y-m-d H:i') ?? '—',
            customer: trim((string) ($order->customer_name ?? '')) ?: '—',
            product: $catalog['compact'],
            sku: '—',
            quantity: $catalog['quantity'] !== '' ? $catalog['quantity'] : '—',
            payment: $paid ? 'Paid' : 'Unpaid',
            fulfilmentStatus: 'None',
            serialStatus: $order->isSerialLocked() ? 'On support order' : 'Not allocated',
            invoiceStatus: 'None',
            shipmentStatus: 'None',
            awbStatus: 'None',
            stage: $stage,
            nextAction: $blocked ? 'View' : 'Review',
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
            packagePhotoRecorded: false,
            statusLabel: $blocked ? 'Blocked' : 'Awaiting Fulfilment',
            productLines: $catalog['lines'],
            productMissing: $catalog['missing'],
        );
    }

    public function fromRin(Order $order, ?CommerceOrder $commerce = null): HardwareFulfilmentOperationalRow
    {
        $created = $order->created_at?->copy()->timezone(HardwareFulfilmentEligibility::CUTOFF_TIMEZONE);
        $paid = $order->isCashfreeVerified();
        $catalog = HardwareFulfilmentProductLines::resolve($commerce, $order);

        return new HardwareFulfilmentOperationalRow(
            sourceId: (string) $order->order_id,
            orderDateIst: $created?->format('Y-m-d H:i') ?? '—',
            customer: trim((string) ($order->customer_name ?? '')) ?: '—',
            product: $catalog['compact'],
            sku: '—',
            quantity: $catalog['quantity'] !== '' ? $catalog['quantity'] : '—',
            payment: $paid ? 'Paid' : 'Unpaid',
            fulfilmentStatus: 'None',
            serialStatus: 'Not allocated',
            invoiceStatus: 'None',
            shipmentStatus: 'None',
            awbStatus: 'None',
            stage: HardwareFulfilmentOperationalStage::BlockedReview,
            nextAction: 'View',
            nextUrl: route('dashboard.orders.customer-360', $order),
            blocker: HardwareAwaitingFulfilmentReason::Rin->operatorNote(),
            fulfilmentId: null,
            supportOrderId: (int) $order->id,
            hasFulfilment: false,
            source: 'RIN',
            section: HardwareOperationsSection::Exceptions,
            nextAnchor: null,
            mutatingAction: false,
            packagePhotoRecorded: false,
            statusLabel: 'Blocked',
            productLines: $catalog['lines'],
            productMissing: $catalog['missing'],
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
        $fulfilment->loadMissing('supportOrder');
        $catalog = HardwareFulfilmentProductLines::resolve($order, $fulfilment->supportOrder);
        $sourceId = (string) $fulfilment->source_id;
        $ownerBlocked = HardwareFulfilmentEligibility::isFrozenSourceId($sourceId)
            || HardwareFulfilmentEligibility::isHoldSourceId($sourceId)
            || HardwareFulfilmentEligibility::isBlockedUntilAuthorized($sourceId);
        $providerError = filled($ready->providerRejection);

        [$stage, $nextAction, $anchor, $statusLabel] = $this->stageAndAction($fulfilment, $ready, $ownerBlocked);
        $photoRecorded = $ready->packagePhotoRecorded();
        $section = HardwareOperationsSection::fromStage($stage, $providerError && ! $ownerBlocked);
        if ($providerError && ! $ownerBlocked) {
            $stage = HardwareFulfilmentOperationalStage::BlockedReview;
            $nextAction = 'View';
            $anchor = null;
            $section = HardwareOperationsSection::Exceptions;
            $statusLabel = 'Blocked';
        }

        $nextUrl = route('inventory.hardware-fulfilments.show', $fulfilment);
        $mutating = ! $ownerBlocked
            && ! $providerError
            && ! in_array($nextAction, ['Ready', 'View', 'Completed'], true);

        return new HardwareFulfilmentOperationalRow(
            sourceId: $sourceId,
            orderDateIst: $ist?->format('Y-m-d H:i') ?? '—',
            customer: $ready->customer ?: '—',
            product: $catalog['compact'],
            sku: trim((string) ($item?->sku ?: $item?->catalog_sku ?: '')) ?: '—',
            quantity: $catalog['quantity'] !== ''
                ? $catalog['quantity']
                : ($ready->quantity !== null ? (string) $ready->quantity : '—'),
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
            mutatingAction: $mutating,
            packagePhotoRecorded: $photoRecorded,
            statusLabel: $statusLabel,
            productLines: $catalog['lines'],
            productMissing: $catalog['missing'],
        );
    }

    /**
     * @return array{0: HardwareFulfilmentOperationalStage, 1: string, 2: string|null, 3: string}
     */
    private function stageAndAction(
        HardwareFulfilment $fulfilment,
        HardwareShipmentReadiness $ready,
        bool $blocked,
    ): array {
        if ($blocked) {
            return [HardwareFulfilmentOperationalStage::BlockedReview, 'View', null, 'Blocked'];
        }

        $photoRecorded = $ready->packagePhotoRecorded();

        if (in_array($fulfilment->state, [HardwareFulfilmentState::Shipped, HardwareFulfilmentState::Synced], true)) {
            if (! $photoRecorded) {
                return [
                    HardwareFulfilmentOperationalStage::Completed,
                    'Upload Package Photo',
                    'hardware-package-evidence',
                    'Ready / Open evidence',
                ];
            }

            return [HardwareFulfilmentOperationalStage::Completed, 'Ready', null, 'Completed'];
        }

        if ($ready->serials === []) {
            return [HardwareFulfilmentOperationalStage::AwaitingSerial, 'Allocate Serial', 'hardware-serial-allocate-form', 'Awaiting Serial'];
        }

        if ($ready->invoice === null || $ready->invoice === '') {
            return [HardwareFulfilmentOperationalStage::AwaitingInvoice, 'Issue Invoice', 'hardware-invoice', 'Awaiting Invoice'];
        }

        if (! $ready->alreadyCreated) {
            $label = $ready->canSelectCourier
                ? 'Select Courier'
                : ($ready->canFetchCourierOptions && $ready->selectedCourierId === null
                    ? 'Get Courier Options'
                    : $ready->actionLabel);
            $anchor = $ready->canSelectCourier || ($ready->canFetchCourierOptions && $ready->selectedCourierId === null)
                ? 'hardware-courier'
                : 'hardware-shipment-create';

            return [HardwareFulfilmentOperationalStage::ReadyForShipment, $label, $anchor, 'Ready for Shipment'];
        }

        if (! filled($ready->awb)) {
            return [HardwareFulfilmentOperationalStage::AwbPending, 'Assign AWB', 'hardware-awb', 'AWB Pending'];
        }

        if ($ready->labelUrl === null) {
            return [HardwareFulfilmentOperationalStage::LabelPackingPending, 'Generate Label', 'hardware-label', 'Label Pending'];
        }

        if ($ready->pickupStatus === 'Not requested') {
            return [HardwareFulfilmentOperationalStage::PickupManifestPending, 'Request Pickup', 'hardware-manifest-pickup', 'Label generated'];
        }

        if ($ready->manifestStatus === 'Not generated') {
            return [HardwareFulfilmentOperationalStage::PickupManifestPending, 'Generate Manifest', 'hardware-manifest-pickup', 'Pickup Requested'];
        }

        if ($ready->readyForPickup) {
            if (! $photoRecorded) {
                return [
                    HardwareFulfilmentOperationalStage::ReadyForPickup,
                    'Upload Package Photo',
                    'hardware-package-evidence',
                    'Package photo pending',
                ];
            }

            return [HardwareFulfilmentOperationalStage::ReadyForPickup, 'Ready', 'hardware-manifest-pickup', 'Ready for Pickup'];
        }

        if ($ready->alreadyCreated && filled($ready->awb)) {
            if (! $photoRecorded) {
                return [
                    HardwareFulfilmentOperationalStage::PickupManifestPending,
                    'Upload Package Photo',
                    'hardware-package-evidence',
                    'Package photo pending',
                ];
            }

            return [HardwareFulfilmentOperationalStage::PickupManifestPending, 'Ready', 'hardware-manifest-pickup', 'Ready for Pickup'];
        }

        return [HardwareFulfilmentOperationalStage::ShipmentCreated, 'View', null, 'Shipment Created'];
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
