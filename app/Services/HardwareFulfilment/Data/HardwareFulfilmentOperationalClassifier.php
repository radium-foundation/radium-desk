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
use App\Services\HardwareFulfilment\HardwareSkuMapService;
use App\Support\HardwareFulfilment\HardwareFulfilmentActivityTimestamps;

final class HardwareFulfilmentOperationalClassifier
{
    public const START_FULFILMENT_BLOCKER = 'Isolated ingest requires a verified Box handoff payload. Desk does not invent one from the support order.';

    public function fromAwaiting(Order $order, ?CommerceOrder $commerce = null): HardwareFulfilmentOperationalRow
    {
        $commerce = $this->uniqueCommerce($order, $commerce);
        $reason = HardwareAwaitingFulfilmentClassifier::reason($order, $commerce);
        $paid = $order->isCashfreeVerified();
        $created = $order->created_at?->copy()->timezone(HardwareFulfilmentEligibility::CUTOFF_TIMEZONE);
        $source = $this->sourcePrefix((string) $order->order_id);
        $catalog = HardwareFulfilmentProductLines::resolve($commerce, $order);
        $presentation = $this->awaitingPresentation($reason, $order);

        return new HardwareFulfilmentOperationalRow(
            sourceId: (string) $order->order_id,
            orderDateIst: $created?->format('Y-m-d H:i') ?? '—',
            lastActionDateIst: HardwareFulfilmentActivityTimestamps::lastActionIstForOrder($order),
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
            stage: $presentation['stage'],
            nextAction: $presentation['nextAction'],
            nextUrl: $presentation['nextUrl'],
            blocker: $presentation['blocker'],
            fulfilmentId: null,
            supportOrderId: (int) $order->id,
            hasFulfilment: false,
            source: $source,
            section: HardwareOperationsSection::fromStage($presentation['stage']),
            nextAnchor: null,
            mutatingAction: $presentation['mutatingAction'],
            packagePhotoRecorded: false,
            statusLabel: $presentation['statusLabel'],
            productLines: $catalog['lines'],
            productMissing: $catalog['missing'],
            productStatusLabel: $catalog['missing'] ? $this->missingProductLabel($reason) : null,
        );
    }

    public function fromRin(Order $order, ?CommerceOrder $commerce = null): HardwareFulfilmentOperationalRow
    {
        $created = $order->created_at?->copy()->timezone(HardwareFulfilmentEligibility::CUTOFF_TIMEZONE);
        $paid = $order->isCashfreeVerified();
        $catalog = HardwareFulfilmentProductLines::resolve($commerce, $order);
        $reason = HardwareAwaitingFulfilmentReason::Rin;

        return new HardwareFulfilmentOperationalRow(
            sourceId: (string) $order->order_id,
            orderDateIst: $created?->format('Y-m-d H:i') ?? '—',
            lastActionDateIst: HardwareFulfilmentActivityTimestamps::lastActionIstForOrder($order),
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
            blocker: $reason->operatorNote(),
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
            productStatusLabel: $catalog['missing'] ? $reason->label() : null,
        );
    }

    private function missingProductLabel(HardwareAwaitingFulfilmentReason $reason): string
    {
        return match ($reason) {
            HardwareAwaitingFulfilmentReason::Hold => 'Owner HOLD / recovery authorization required',
            HardwareAwaitingFulfilmentReason::Blocked => 'Blocked until authorized',
            default => $reason->label(),
        };
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
        $ownerBlocked = HardwareFulfilmentEligibility::isFrozenForFulfilment($sourceId, $order)
            || HardwareFulfilmentEligibility::isHoldSourceId($sourceId)
            || HardwareFulfilmentEligibility::isBlockedUntilAuthorized($sourceId);
        $readinessBlocker = (! $ownerBlocked && $fulfilment->state === HardwareFulfilmentState::Ingested)
            ? HardwareFulfilmentEligibility::isolatedTargetBlocker($fulfilment, $order)
            : null;
        $mappingMissing = ! $ownerBlocked
            && $fulfilment->state !== HardwareFulfilmentState::Ingested
            && $ready->serials === []
            && $this->missingSkuMap($fulfilment, $order);
        $unrecoverableProviderError = $this->providerRejectionIsUnrecoverable($ready, $ownerBlocked);

        [$stage, $nextAction, $anchor, $statusLabel] = $this->stageAndAction(
            $fulfilment,
            $ready,
            $ownerBlocked,
            $readinessBlocker,
            $mappingMissing,
        );
        $photoRecorded = $ready->packagePhotoRecorded();
        $section = HardwareOperationsSection::fromStage($stage, $unrecoverableProviderError);
        if ($unrecoverableProviderError) {
            $stage = HardwareFulfilmentOperationalStage::BlockedReview;
            $nextAction = 'View';
            $anchor = null;
            $section = HardwareOperationsSection::Exceptions;
            $statusLabel = 'Blocked';
        }

        $nextUrl = route('inventory.hardware-fulfilments.show', $fulfilment);
        $mutating = ! $ownerBlocked
            && ! $unrecoverableProviderError
            && ! $mappingMissing
            && ! in_array($nextAction, ['Ready', 'View', 'Completed'], true);

        $blocker = $ownerBlocked
            ? 'Owner-blocked. Do not advance from this queue.'
            : ($mappingMissing
                ? HardwareAwaitingFulfilmentReason::ProductMappingRequired->operatorNote()
                : ($readinessBlocker ?: ($ready->providerRejection ?: ($ready->blockers[0] ?? null))));

        return new HardwareFulfilmentOperationalRow(
            sourceId: $sourceId,
            orderDateIst: $ist?->format('Y-m-d H:i') ?? '—',
            lastActionDateIst: HardwareFulfilmentActivityTimestamps::lastActionIstForFulfilment($fulfilment),
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
            blocker: $blocker,
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
            allocatedSerialNumbers: $ready->serials,
            expectedSerialQuantity: $ready->quantity,
            productStatusLabel: $catalog['missing']
                ? ($mappingMissing ? HardwareAwaitingFulfilmentReason::ProductMappingRequired->label() : 'Product data missing')
                : null,
        );
    }

    /**
     * @return array{stage: HardwareFulfilmentOperationalStage, nextAction: string, nextUrl: string, blocker: string, mutatingAction: bool, statusLabel: string}
     */
    private function awaitingPresentation(HardwareAwaitingFulfilmentReason $reason, Order $order): array
    {
        if ($reason === HardwareAwaitingFulfilmentReason::RecoveredCommerce) {
            return [
                'stage' => HardwareFulfilmentOperationalStage::AwaitingFulfilment,
                'nextAction' => 'Open Fulfilment',
                'nextUrl' => route('inventory.hardware-fulfilments.awaiting.action-dialog', $order),
                'blocker' => $reason->operatorNote(),
                'mutatingAction' => true,
                'statusLabel' => $reason->label(),
            ];
        }

        if ($reason === HardwareAwaitingFulfilmentReason::ReviewCandidate) {
            return [
                'stage' => HardwareFulfilmentOperationalStage::AwaitingFulfilment,
                'nextAction' => 'Review',
                'nextUrl' => route('dashboard.orders.customer-360', $order),
                'blocker' => self::START_FULFILMENT_BLOCKER,
                'mutatingAction' => false,
                'statusLabel' => 'Awaiting Fulfilment',
            ];
        }

        if ($reason === HardwareAwaitingFulfilmentReason::AwaitingHandoff) {
            return [
                'stage' => HardwareFulfilmentOperationalStage::AwaitingFulfilment,
                'nextAction' => 'View',
                'nextUrl' => route('dashboard.orders.customer-360', $order),
                'blocker' => $reason->operatorNote(),
                'mutatingAction' => false,
                'statusLabel' => $reason->label(),
            ];
        }

        return [
            'stage' => HardwareFulfilmentOperationalStage::BlockedReview,
            'nextAction' => 'View',
            'nextUrl' => route('dashboard.orders.customer-360', $order),
            'blocker' => $reason->operatorNote(),
            'mutatingAction' => false,
            'statusLabel' => match ($reason) {
                HardwareAwaitingFulfilmentReason::Hold => 'Owner HOLD / recovery authorization required',
                HardwareAwaitingFulfilmentReason::SplitTender,
                HardwareAwaitingFulfilmentReason::ProductMappingRequired => $reason->label(),
                HardwareAwaitingFulfilmentReason::Frozen => 'Frozen',
                default => 'Blocked',
            },
        ];
    }

    /**
     * @return array{0: HardwareFulfilmentOperationalStage, 1: string, 2: string|null, 3: string}
     */
    private function stageAndAction(
        HardwareFulfilment $fulfilment,
        HardwareShipmentReadiness $ready,
        bool $blocked,
        ?string $readinessBlocker = null,
        bool $mappingMissing = false,
    ): array {
        if ($blocked) {
            return [HardwareFulfilmentOperationalStage::BlockedReview, 'View', null, 'Blocked'];
        }

        if ($fulfilment->state === HardwareFulfilmentState::Ingested) {
            if ($readinessBlocker !== null) {
                return [HardwareFulfilmentOperationalStage::BlockedReview, 'View', null, 'Ingested'];
            }

            return [
                HardwareFulfilmentOperationalStage::AwaitingFulfilment,
                'Ready for Fulfilment',
                'hardware-mark-ready',
                'Ingested',
            ];
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
            if ($mappingMissing) {
                return [
                    HardwareFulfilmentOperationalStage::BlockedReview,
                    'View',
                    null,
                    HardwareAwaitingFulfilmentReason::ProductMappingRequired->label(),
                ];
            }

            return [HardwareFulfilmentOperationalStage::AwaitingSerial, 'Allocate Serial', 'hardware-serial-allocate-form', 'Awaiting Serial'];
        }

        if ($ready->invoice === null || $ready->invoice === '') {
            return [HardwareFulfilmentOperationalStage::AwaitingInvoice, 'Issue Invoice', 'hardware-invoice', 'Awaiting Invoice'];
        }

        if (! $ready->alreadyCreated) {
            [$label, $anchor] = $this->shipmentPrepAction($ready);

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

    /**
     * Cached courier options do not keep the operator on Select Courier
     * after a valid selection already permits create/reconcile.
     *
     * @return array{0: string, 1: string}
     */
    private function shipmentPrepAction(HardwareShipmentReadiness $ready): array
    {
        if ($ready->canAttachMeasuredParcel) {
            return ['Enter Package Dimensions', 'hardware-parcel-measure'];
        }

        if ($ready->canCreate) {
            return [$ready->actionLabel, 'hardware-shipment-create'];
        }

        if ($ready->canSelectCourier && $ready->selectedCourierId === null) {
            return ['Select Courier', 'hardware-courier'];
        }

        if ($ready->canFetchCourierOptions && $ready->selectedCourierId === null) {
            return ['Get Courier Options', 'hardware-courier'];
        }

        return [$ready->actionLabel, 'hardware-shipment-create'];
    }

    /**
     * An unbound local provider_rejected row is historical evidence, not a
     * permanent Blocked state, when create/courier/parcel retry is still open.
     */
    private function providerRejectionIsUnrecoverable(HardwareShipmentReadiness $ready, bool $ownerBlocked): bool
    {
        if ($ownerBlocked || $ready->alreadyCreated || ! filled($ready->providerRejection)) {
            return false;
        }

        if ($ready->canCreate
            || $ready->canFetchCourierOptions
            || $ready->canSelectCourier
            || $ready->canAttachMeasuredParcel) {
            return false;
        }

        if ($ready->serials === [] || $ready->invoice === null || $ready->invoice === '') {
            return false;
        }

        return true;
    }

    private function missingSkuMap(HardwareFulfilment $fulfilment, ?CommerceOrder $order): bool
    {
        if ($order === null) {
            return false;
        }

        $order->loadMissing('items');
        $maps = app(HardwareSkuMapService::class);
        foreach ($order->items as $item) {
            if (! HardwareFulfilmentEligibility::isPhysicalCommerceItem($item)) {
                continue;
            }

            $modelId = $item->model_id !== null ? (int) $item->model_id : null;
            if ($maps->findProduct($fulfilment->channel, $modelId) === null) {
                return true;
            }
        }

        return false;
    }

    private function uniqueCommerce(Order $order, ?CommerceOrder $commerce): ?CommerceOrder
    {
        if ($commerce !== null) {
            $commerce->loadMissing('items');

            return $commerce;
        }

        $sourceId = strtoupper(trim((string) $order->order_id));
        $matches = CommerceOrder::query()
            ->with('items')
            ->where(function ($query) use ($order, $sourceId): void {
                $query->where('support_order_id', $order->id)
                    ->orWhereRaw('UPPER(source_id) = ?', [$sourceId]);
            })
            ->get()
            ->unique('id')
            ->values();

        return $matches->count() === 1 ? $matches->first() : null;
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
