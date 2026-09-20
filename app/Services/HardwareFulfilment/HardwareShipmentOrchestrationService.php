<?php

namespace App\Services\HardwareFulfilment;

use App\Enums\HardwareFulfilmentState;
use App\Models\HardwareFulfilment;
use App\Models\Shipment;
use App\Models\User;
use App\Services\HardwareFulfilment\Data\HardwareShipmentCourierQuote;
use App\Services\HardwareFulfilment\Data\HardwareShipmentOrchestrationOutcome;
use App\Services\HardwareFulfilment\Data\HardwareShipmentReadiness;
use Illuminate\Validation\ValidationException;
use Throwable;

class HardwareShipmentOrchestrationService
{
    public function __construct(
        private readonly HardwareFulfilmentParcelSnapshotService $snapshots,
        private readonly HardwareShipmentCourierOptionsService $couriers,
        private readonly HardwareShipmentCourierSelector $selector,
        private readonly HardwareShipmentService $shipments,
        private readonly HardwareShipmentDocumentsService $documents,
        private readonly HardwareShipmentEligibility $eligibility,
        private readonly HardwarePickupResolver $pickups,
        private readonly HardwareShipmentCollectionModeResolver $collectionModes,
    ) {}

    public function shouldAutoPrepare(HardwareFulfilment $fulfilment, HardwareShipmentReadiness $ready): bool
    {
        if ($ready->labelUrl !== null) {
            return false;
        }

        if ($fulfilment->state === HardwareFulfilmentState::CancelledHistoricalDuplicate) {
            return false;
        }

        if (HardwareFulfilmentEligibility::isFrozenForFulfilment((string) $fulfilment->source_id, $fulfilment->commerceOrder)) {
            return false;
        }

        if ($ready->canAttachMeasuredParcel) {
            return false;
        }

        if (! $this->providerReady($ready)) {
            return false;
        }

        if ($ready->canShipAndGenerateLabel || $ready->canConfirmRecommendedCourier) {
            return true;
        }

        return $ready->canFetchCourierOptions
            || $ready->canSelectCourier
            || $ready->canCreate
            || $ready->canAssignAwb
            || $ready->canGenerateLabel;
    }

    public function preparePrerequisites(HardwareFulfilment $fulfilment, ?User $actor = null): HardwareFulfilment
    {
        $this->assertNotFrozen($fulfilment);

        $this->snapshots->attachIfEligible($fulfilment->fresh() ?? $fulfilment, $actor);
        $fulfilment = $fulfilment->fresh() ?? $fulfilment;

        $ready = $this->eligibility->inspect($fulfilment);
        if ($ready->canAttachMeasuredParcel || ! $this->providerReady($ready)) {
            return $fulfilment;
        }

        if ($ready->labelUrl !== null) {
            return $fulfilment;
        }

        if ($this->needsCourierOptionsRefresh($fulfilment, $ready)) {
            $this->couriers->fetch($fulfilment, $actor);
            $fulfilment = $fulfilment->fresh() ?? $fulfilment;
        }

        if ($this->autoSelectEnabled()) {
            $ready = $this->eligibility->inspect($fulfilment);
            if (! $ready->canShipAndGenerateLabel) {
                $this->selectRecommendedCourier($fulfilment, $actor);
                $fulfilment = $fulfilment->fresh() ?? $fulfilment;
            }
        }

        return $fulfilment;
    }

    public function confirmRecommendedCourier(HardwareFulfilment $fulfilment, ?User $actor = null): HardwareFulfilment
    {
        $fulfilment = $this->preparePrerequisites($fulfilment, $actor);
        $this->selectRecommendedCourier($fulfilment, $actor);

        return $fulfilment->fresh() ?? $fulfilment;
    }

    public function shipAndGenerateLabel(HardwareFulfilment $fulfilment, ?User $actor = null): HardwareShipmentOrchestrationOutcome
    {
        $this->assertNotFrozen($fulfilment);

        $fulfilment = $this->preparePrerequisites($fulfilment, $actor);
        $ready = $this->eligibility->inspect($fulfilment);

        if (! $ready->canShipAndGenerateLabel) {
            if ($ready->canConfirmRecommendedCourier) {
                throw ValidationException::withMessages([
                    'courier_id' => 'Confirm the recommended courier before shipping and generating the label.',
                ]);
            }

            $blocker = $ready->blockers[0] ?? 'Shipment prerequisites are not ready.';
            throw ValidationException::withMessages([
                'shipping' => $blocker,
            ]);
        }

        $shipmentCreated = false;
        $awbAssigned = false;
        $labelGenerated = false;
        $labelRetryRequired = false;

        $shipment = $this->existingShipment($fulfilment);

        if ($shipment !== null && filled($shipment->label_url)) {
            return $this->completedOutcome($shipment, alreadyComplete: true);
        }

        if ($shipment === null || ! $shipment->isBound()) {
            $this->couriers->requireValidSelection($fulfilment);
            $shipment = $this->shipments->createShipment($fulfilment, $actor);
            $shipmentCreated = true;
            $fulfilment = $fulfilment->fresh() ?? $fulfilment;
            $shipment = $shipment->fresh() ?? $shipment;
        }

        if (! filled($shipment->awb)) {
            $shipment = $this->shipments->assignAwb($fulfilment->fresh() ?? $fulfilment, $actor);
            $awbAssigned = true;
            $fulfilment = $fulfilment->fresh() ?? $fulfilment;
            $shipment = $shipment->fresh() ?? $shipment;
        }

        if (! filled($shipment->label_url)) {
            try {
                $shipment = $this->documents->generateLabel($fulfilment->fresh() ?? $fulfilment, $actor);
                $labelGenerated = true;
            } catch (ValidationException $exception) {
                return new HardwareShipmentOrchestrationOutcome(
                    shipment: $shipment->fresh() ?? $shipment,
                    shipmentCreated: $shipmentCreated,
                    awbAssigned: $awbAssigned,
                    labelGenerated: false,
                    labelReady: false,
                    labelRetryRequired: true,
                    message: 'Shipment created and AWB assigned. Label generation needs retry.',
                );
            } catch (Throwable) {
                return new HardwareShipmentOrchestrationOutcome(
                    shipment: $shipment->fresh() ?? $shipment,
                    shipmentCreated: $shipmentCreated,
                    awbAssigned: $awbAssigned,
                    labelGenerated: false,
                    labelReady: false,
                    labelRetryRequired: true,
                    message: 'Shipment created and AWB assigned. Label generation needs retry.',
                );
            }
        }

        return $this->completedOutcome(
            $shipment->fresh() ?? $shipment,
            shipmentCreated: $shipmentCreated,
            awbAssigned: $awbAssigned,
            labelGenerated: $labelGenerated,
        );
    }

    public function recommendedCourierLabel(HardwareFulfilment $fulfilment): ?string
    {
        $option = $this->recommendedCourierOption($fulfilment);
        if ($option === null) {
            return null;
        }

        $name = trim((string) ($option['courier_name'] ?? ''));
        $id = trim((string) ($option['courier_id'] ?? ''));
        if ($name === '' && $id === '') {
            return null;
        }

        return $name !== '' && $id !== '' ? $name.' ('.$id.')' : ($name !== '' ? $name : $id);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function recommendedCourierOption(HardwareFulfilment $fulfilment): ?array
    {
        $options = HardwareShipmentCourierQuote::options($fulfilment);
        if ($options === []) {
            return null;
        }

        return $this->selector->choose(
            $options,
            recommendedId: HardwareShipmentCourierQuote::recommendedCourierId($fulfilment),
            allowUnrankedEligible: false,
        );
    }

    public function autoSelectEnabled(): bool
    {
        return (bool) config('shipping.auto_select_recommended_courier', false);
    }

    private function selectRecommendedCourier(HardwareFulfilment $fulfilment, ?User $actor): void
    {
        $ready = $this->eligibility->inspect($fulfilment);
        if ($ready->selectedCourierId !== null && $ready->canShipAndGenerateLabel) {
            return;
        }

        if ($this->needsCourierOptionsRefresh($fulfilment, $ready)) {
            $this->couriers->fetch($fulfilment, $actor);
            $fulfilment = $fulfilment->fresh() ?? $fulfilment;
        }

        $option = $this->recommendedCourierOption($fulfilment);
        if ($option === null) {
            throw ValidationException::withMessages([
                'courier_id' => 'Shiprocket returned no currently serviceable recommended courier for this fulfilment.',
            ]);
        }

        $this->couriers->select($fulfilment, (string) $option['courier_id'], $actor);
    }

    private function needsCourierOptionsRefresh(HardwareFulfilment $fulfilment, HardwareShipmentReadiness $ready): bool
    {
        if ($ready->alreadyCreated && filled($ready->awb)) {
            return false;
        }

        if (! $ready->canFetchCourierOptions && ! $ready->canSelectCourier && $ready->courierOptions === []) {
            return false;
        }

        try {
            $inputs = $this->eligibility->quoteInputs($fulfilment);
            $pickupPostcode = $this->pickups->requirePostcodeForBranch($inputs['branch']);
            $fingerprint = HardwareShipmentCourierQuote::fingerprint(
                $inputs,
                $pickupPostcode,
                $this->collectionModes->forFulfilment($fulfilment)->serviceabilityCod(),
                $this->providerOrderId($fulfilment),
            );
        } catch (ValidationException) {
            return false;
        }

        return ! HardwareShipmentCourierQuote::isFresh($fulfilment, $fingerprint)
            || HardwareShipmentCourierQuote::options($fulfilment) === [];
    }

    private function providerOrderId(HardwareFulfilment $fulfilment): ?string
    {
        $shipment = $this->existingShipment($fulfilment);
        $orderId = trim((string) ($shipment?->external_order_id ?? ''));

        return $orderId === '' ? null : $orderId;
    }

    private function existingShipment(HardwareFulfilment $fulfilment): ?Shipment
    {
        $fulfilment->loadMissing('shipment');

        return $fulfilment->shipment_id !== null
            ? $fulfilment->shipment
            : Shipment::query()->where('hardware_fulfilment_id', $fulfilment->id)->first();
    }

    private function providerReady(HardwareShipmentReadiness $ready): bool
    {
        return ! in_array('Shipping is not enabled', $ready->blockers, true)
            && $ready->providerRejection === null;
    }

    private function completedOutcome(
        Shipment $shipment,
        bool $shipmentCreated = false,
        bool $awbAssigned = false,
        bool $labelGenerated = false,
        bool $alreadyComplete = false,
    ): HardwareShipmentOrchestrationOutcome {
        if ($alreadyComplete) {
            return new HardwareShipmentOrchestrationOutcome(
                shipment: $shipment,
                shipmentCreated: false,
                awbAssigned: false,
                labelGenerated: false,
                labelReady: true,
                labelRetryRequired: false,
                message: 'Shipping label is ready.',
            );
        }

        $parts = [];
        if ($shipmentCreated) {
            $parts[] = 'Shipment created';
        }
        if ($awbAssigned) {
            $parts[] = 'AWB assigned';
        }
        if ($labelGenerated) {
            $parts[] = 'label generated';
        }

        $message = $parts === []
            ? 'Shipping label is ready.'
            : ucfirst(implode(', ', $parts)).'.';

        return new HardwareShipmentOrchestrationOutcome(
            shipment: $shipment,
            shipmentCreated: $shipmentCreated,
            awbAssigned: $awbAssigned,
            labelGenerated: $labelGenerated,
            labelReady: filled($shipment->label_url),
            labelRetryRequired: false,
            message: $message,
        );
    }

    private function assertNotFrozen(HardwareFulfilment $fulfilment): void
    {
        if (HardwareFulfilmentEligibility::isFrozenForFulfilment((string) $fulfilment->source_id, $fulfilment->commerceOrder)) {
            throw ValidationException::withMessages([
                'fulfilment' => 'Frozen pending hardware orders cannot be shipped.',
            ]);
        }
    }
}
