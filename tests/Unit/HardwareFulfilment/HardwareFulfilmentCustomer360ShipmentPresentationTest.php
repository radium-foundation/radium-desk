<?php

namespace Tests\Unit\HardwareFulfilment;

use App\Services\HardwareFulfilment\Data\HardwareShipmentReadiness;
use App\Support\HardwareFulfilment\HardwareFulfilmentCustomer360ShipmentPresentation;
use Tests\TestCase;

class HardwareFulfilmentCustomer360ShipmentPresentationTest extends TestCase
{
    public function test_null_readiness_is_not_shipped(): void
    {
        $this->assertSame(
            [
                'summary' => 'Not shipped yet',
                'awb' => null,
                'carrier' => null,
                'status' => null,
            ],
            HardwareFulfilmentCustomer360ShipmentPresentation::present(null),
        );
    }

    public function test_invoice_without_shipment_is_ready_for_shipment(): void
    {
        $ready = $this->ready(invoice: 'INV-1');

        $this->assertSame('Ready for shipment', HardwareFulfilmentCustomer360ShipmentPresentation::present($ready)['summary']);
    }

    public function test_awb_uses_authoritative_status_when_present(): void
    {
        $ready = $this->ready(awb: 'AWB123', courier: 'Delhivery');

        $payload = HardwareFulfilmentCustomer360ShipmentPresentation::present($ready);

        $this->assertSame('AWB ASSIGNED', $payload['summary']);
        $this->assertSame('AWB123', $payload['awb']);
        $this->assertSame('Delhivery', $payload['carrier']);
    }

    public function test_awb_without_status_claims_shipped(): void
    {
        $ready = $this->ready(awb: 'AWB123', courier: 'Delhivery', status: '');

        $this->assertSame('Shipped', HardwareFulfilmentCustomer360ShipmentPresentation::present($ready)['summary']);
    }

    public function test_pickup_state_is_reported(): void
    {
        $ready = $this->ready(readyForPickup: true);

        $this->assertSame('Ready for pickup', HardwareFulfilmentCustomer360ShipmentPresentation::present($ready)['summary']);
    }

    private function ready(
        ?string $invoice = null,
        ?string $awb = null,
        ?string $courier = null,
        bool $readyForPickup = false,
        ?string $status = null,
    ): HardwareShipmentReadiness {
        $resolvedStatus = $status ?? ($awb ? 'AWB ASSIGNED' : 'None');

        return new HardwareShipmentReadiness(
            canCreate: false,
            blockers: [],
            status: $resolvedStatus,
            pickupBranch: null,
            pickupLocation: null,
            shipTo: null,
            parcel: null,
            invoice: $invoice,
            serials: [],
            order: 'RDE1',
            product: 'MFS',
            alreadyCreated: $awb !== null,
            awb: $awb,
            courier: $courier,
            readyForPickup: $readyForPickup,
        );
    }
}
