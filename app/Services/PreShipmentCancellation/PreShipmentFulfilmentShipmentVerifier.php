<?php

namespace App\Services\PreShipmentCancellation;

use App\Enums\HardwareFulfilmentState;
use App\Enums\PreShipmentEligibilityFailureCode;
use App\Models\HardwareFulfilment;

final class PreShipmentFulfilmentShipmentVerifier
{
    public function assertNoShipmentEvidence(?HardwareFulfilment $fulfilment): ?PreShipmentEligibilityFailureCode
    {
        if ($fulfilment === null) {
            return null;
        }

        $state = $fulfilment->state;

        if ($state !== null && in_array($state, [
            HardwareFulfilmentState::ShipmentCreated,
            HardwareFulfilmentState::AwbAssigned,
            HardwareFulfilmentState::Shipped,
            HardwareFulfilmentState::Synced,
        ], true)) {
            return PreShipmentEligibilityFailureCode::ShipmentOrAwb;
        }

        if ($state !== null && $state->rank() >= HardwareFulfilmentState::SerialsAllocated->rank()
            && $state->rank() > 0) {
            return PreShipmentEligibilityFailureCode::FulfilmentBeyondScope;
        }

        if ($fulfilment->serials_allocated_at !== null
            || $fulfilment->statutory_invoice_id !== null
            || filled($fulfilment->awb)
            || filled($fulfilment->provider_awb)
            || filled($fulfilment->shipment_id)
            || filled($fulfilment->shipment_no)
            || $fulfilment->ready_for_pickup_at !== null) {
            if ($fulfilment->serials_allocated_at !== null || $state === HardwareFulfilmentState::SerialsAllocated) {
                return PreShipmentEligibilityFailureCode::SerialAllocated;
            }
            if ($fulfilment->statutory_invoice_id !== null || $state === HardwareFulfilmentState::InvoiceIssued) {
                return PreShipmentEligibilityFailureCode::StatutoryInvoiceIssued;
            }

            return PreShipmentEligibilityFailureCode::ShipmentOrAwb;
        }

        if ($fulfilment->serials()->whereNotNull('serial_number')->exists()) {
            return PreShipmentEligibilityFailureCode::SerialAllocated;
        }

        return null;
    }
}
