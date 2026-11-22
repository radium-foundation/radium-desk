<?php

namespace App\Services\PreShipmentCancellation;

use App\Enums\HardwareFulfilmentState;
use App\Enums\PreShipmentCancellationOrderFamily;
use App\Enums\PreShipmentCancellationStatus;
use App\Enums\PreShipmentEligibilityFailureCode;
use App\Models\CommerceOrder;
use App\Models\HardwareFulfilment;
use App\Models\Order;
use App\Models\PreShipmentCancellationRequest;
use App\Support\BusinessOrderId;

final class PreShipmentCancellationEligibilityService
{
    public function __construct(
        private readonly PreShipmentCancellationFeature $feature,
        private readonly PreShipmentFulfilmentShipmentVerifier $shipmentVerifier,
    ) {}

    /**
     * @return array{eligible: bool, phase: 'A'|'B'|null, code: ?PreShipmentEligibilityFailureCode, commerce: ?CommerceOrder, fulfilment: ?HardwareFulfilment}
     */
    public function assess(Order $order): array
    {
        if (! $this->feature->enabled()) {
            return $this->reject(PreShipmentEligibilityFailureCode::FeatureDisabled);
        }

        $family = PreShipmentCancellationOrderFamily::fromSourceId($order->order_id);
        if ($family === null || ! BusinessOrderId::isHardwareByPrefix($order->order_id)) {
            return $this->reject(PreShipmentEligibilityFailureCode::UnsupportedFamily);
        }

        if (! $order->isCashfreeVerified()) {
            return $this->reject(PreShipmentEligibilityFailureCode::Unpaid);
        }

        if ($this->hasOpenCase($order)) {
            return $this->reject(PreShipmentEligibilityFailureCode::ExistingOpenCase);
        }

        $commerce = CommerceOrder::query()
            ->where('support_order_id', $order->id)
            ->orWhereRaw('UPPER(source_id) = ?', [strtoupper((string) $order->order_id)])
            ->orderByDesc('id')
            ->first();

        $fulfilment = HardwareFulfilment::query()
            ->where('support_order_id', $order->id)
            ->when($commerce !== null, fn ($q) => $q->orWhere('commerce_order_id', $commerce->id))
            ->orderByDesc('id')
            ->first();

        if ($fulfilment !== null && $fulfilment->state === HardwareFulfilmentState::CancelledPreShipment) {
            return $this->reject(PreShipmentEligibilityFailureCode::AmbiguousState, $commerce, $fulfilment);
        }

        $shipmentFailure = $this->shipmentVerifier->assertNoShipmentEvidence($fulfilment);
        if ($shipmentFailure !== null) {
            return $this->reject($shipmentFailure, $commerce, $fulfilment);
        }

        $phase = $fulfilment === null ? 'A' : 'B';

        if ($phase === 'B' && $fulfilment !== null) {
            $allowed = in_array($fulfilment->state, [
                HardwareFulfilmentState::Paid,
                HardwareFulfilmentState::Ingested,
                HardwareFulfilmentState::ReadyForFulfilment,
            ], true);
            if (! $allowed) {
                return $this->reject(PreShipmentEligibilityFailureCode::FulfilmentBeyondScope, $commerce, $fulfilment);
            }
        }

        return [
            'eligible' => true,
            'phase' => $phase,
            'code' => null,
            'commerce' => $commerce,
            'fulfilment' => $fulfilment,
        ];
    }

    private function hasOpenCase(Order $order): bool
    {
        return PreShipmentCancellationRequest::query()
            ->where('order_id', $order->id)
            ->whereNotIn('status', array_map(
                fn ($s) => $s->value,
                PreShipmentCancellationStatus::terminal(),
            ))
            ->exists();
    }

    /**
     * @return array{eligible: false, phase: null, code: PreShipmentEligibilityFailureCode, commerce: ?CommerceOrder, fulfilment: ?HardwareFulfilment}
     */
    private function reject(
        PreShipmentEligibilityFailureCode $code,
        ?CommerceOrder $commerce = null,
        ?HardwareFulfilment $fulfilment = null,
    ): array {
        return [
            'eligible' => false,
            'phase' => null,
            'code' => $code,
            'commerce' => $commerce,
            'fulfilment' => $fulfilment,
        ];
    }
}
