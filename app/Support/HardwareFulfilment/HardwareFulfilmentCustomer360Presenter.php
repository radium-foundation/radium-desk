<?php

namespace App\Support\HardwareFulfilment;

use App\Enums\HardwareAwaitingFulfilmentReason;
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
            $ready = $this->eligibility->inspect($fulfilment);
            $row = $this->classifier->fromFulfilment($fulfilment, $ready);
        } elseif (str_starts_with($sourceId, 'RIN')) {
            $row = $this->classifier->fromRin($order);
        } else {
            $row = $this->classifier->fromAwaiting($order);
        }

        $canOperate = HardwareFulfilmentAccess::allows($user);
        $canOpen = $fulfilment !== null
            && $canOperate
            && HardwareFulfilmentNavigation::userCanOpen($user, $fulfilment);

        return [
            'row' => $row,
            'ready' => $ready,
            'milestones' => HardwareFulfilmentStepper::milestones(),
            'currentIndex' => HardwareFulfilmentStepper::currentIndex($row, $ready),
            'currentCaption' => HardwareFulfilmentStepper::currentCaption($row),
            'activity' => $this->activity($ready),
            'canStart' => false,
            'startBlocker' => $this->startBlocker($row, $order),
            'showUrl' => $canOpen ? $row->fulfilmentUrl() : null,
            'primaryUrl' => $canOperate ? $row->primaryUrl() : $row->customer360Url(),
            'canOperate' => $canOperate,
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

        if (HardwareAwaitingFulfilmentClassifier::reason($order) !== HardwareAwaitingFulfilmentReason::ReviewCandidate) {
            return $row->blocker;
        }

        return 'Hardware fulfilment has not started.';
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
