<?php

namespace App\Support\HardwareFulfilment;

use App\Enums\HardwareAwaitingFulfilmentReason;
use App\Models\CommerceOrder;
use App\Models\Order;
use App\Models\User;
use App\Services\HardwareFulfilment\Data\HardwareAwaitingFulfilmentClassifier;
use App\Services\HardwareFulfilment\Data\HardwareFulfilmentOperationalClassifier;
use App\Services\HardwareFulfilment\Data\HardwareFulfilmentOperationalRow;
use App\Services\HardwareFulfilment\Data\HardwareFulfilmentStepper;
use App\Services\HardwareFulfilment\Data\HardwareShipmentReadiness;
use App\Services\HardwareFulfilment\HardwareShipmentEligibility;

/**
 * Read-only Hardware Fulfilment card for Customer 360.
 * Does not ingest, allocate, invoice, or ship.
 */
final class HardwareFulfilmentCustomer360Presenter
{
    public function __construct(
        private readonly HardwareFulfilmentOperationalClassifier $classifier,
        private readonly HardwareShipmentEligibility $eligibility,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function present(?Order $order, ?User $user): ?array
    {
        if ($order === null) {
            return null;
        }

        $sourceId = strtoupper(trim((string) $order->order_id));
        $isHardwareShaped = str_starts_with($sourceId, 'RDE') || str_starts_with($sourceId, 'RIN');
        $fulfilment = HardwareFulfilmentNavigation::resolveForOrder($order);

        if (! $isHardwareShaped && $fulfilment === null) {
            return null;
        }

        $ready = null;
        if ($fulfilment !== null) {
            $fulfilment->loadMissing(['commerceOrder.items', 'supportOrder']);
            $ready = $this->eligibility->inspect($fulfilment);
            $row = $this->classifier->fromFulfilment($fulfilment, $ready);
        } else {
            $commerce = CommerceOrder::query()
                ->with('items')
                ->where(function ($query) use ($order): void {
                    $query->where('support_order_id', $order->id)
                        ->orWhere('source_id', $order->order_id);
                })
                ->first();
            $row = str_starts_with($sourceId, 'RIN')
                ? $this->classifier->fromRin($order, $commerce)
                : $this->classifier->fromAwaiting($order, $commerce);
        }

        $canOperate = HardwareFulfilmentAccess::allows($user);
        $canOpen = $fulfilment !== null
            && $canOperate
            && HardwareFulfilmentNavigation::userCanOpen($user, $fulfilment);
        $canDownloadDocuments = $fulfilment !== null
            && HardwareFulfilmentAccess::allowsDocumentDownload($user);

        return [
            'row' => $row,
            'ready' => $ready,
            'serialLabel' => $row->serialStatusLabel(),
            'fulfilmentLabel' => $row->fulfilmentStatusLabel(),
            'shipment' => HardwareFulfilmentCustomer360ShipmentPresentation::present($ready),
            'milestones' => HardwareFulfilmentStepper::milestones(),
            'currentIndex' => HardwareFulfilmentStepper::currentIndex($row, $ready),
            'currentCaption' => HardwareFulfilmentStepper::currentCaption($row),
            'activity' => $this->activity($ready),
            'canStart' => false,
            'startBlocker' => $this->startBlocker($row, $order),
            'showUrl' => $canOpen ? $row->fulfilmentUrl() : null,
            'primaryUrl' => $canOperate ? $row->primaryUrl() : $row->customer360Url(),
            'canOperate' => $canOperate,
            'canDownloadDocuments' => $canDownloadDocuments,
        ];
    }

    private function startBlocker(HardwareFulfilmentOperationalRow $row, Order $order): ?string
    {
        if ($row->hasFulfilment) {
            return null;
        }

        if (str_starts_with(strtoupper((string) $order->order_id), 'RIN')) {
            return 'Hardware cannot start yet. Verified RIN → Desk hardware mapping is required.';
        }

        if (HardwareAwaitingFulfilmentClassifier::reason($order, $this->commerceFor($order)) !== HardwareAwaitingFulfilmentReason::ReviewCandidate) {
            return $row->blocker;
        }

        return 'Hardware fulfilment has not started.';
    }

    private function commerceFor(Order $order): ?CommerceOrder
    {
        $matches = CommerceOrder::query()
            ->with('items')
            ->where(function ($query) use ($order): void {
                $query->where('support_order_id', $order->id)
                    ->orWhere('source_id', $order->order_id);
            })
            ->get()
            ->unique('id')
            ->values();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /**
     * @return list<array{label: string, done: bool}>
     */
    private function activity(?HardwareShipmentReadiness $ready): array
    {
        if ($ready === null) {
            return [];
        }

        return [
            ['label' => 'Shipment created', 'done' => $ready->alreadyCreated],
            ['label' => 'AWB assigned', 'done' => filled($ready->awb)],
            ['label' => 'Label generated', 'done' => $ready->labelUrl !== null],
            ['label' => 'Pickup requested', 'done' => $ready->pickupStatus === 'Requested'],
            ['label' => 'Manifest generated', 'done' => $ready->manifestStatus !== 'Not generated'],
            ['label' => 'Package photo', 'done' => $ready->packagePhotoRecorded()],
        ];
    }
}
