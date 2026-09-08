<?php

namespace Tests\Unit\HardwareFulfilment;

use App\Enums\HardwareFulfilmentOperationalStage;
use App\Enums\HardwareOperationsSection;
use App\Services\HardwareFulfilment\Data\HardwareFulfilmentOperationalRow;
use App\Services\HardwareFulfilment\Data\HardwareFulfilmentStepper;
use App\Services\HardwareFulfilment\Data\HardwareShipmentReadiness;
use Tests\TestCase;

class HardwareFulfilmentStepperTest extends TestCase
{
    public function test_awaiting_row_starts_at_review(): void
    {
        $row = $this->row(HardwareFulfilmentOperationalStage::AwaitingFulfilment, false);

        $this->assertSame(0, HardwareFulfilmentStepper::currentIndex($row));
        $this->assertCount(11, HardwareFulfilmentStepper::milestones());
        $this->assertSame('Review', HardwareFulfilmentStepper::milestones()[0]);
        $this->assertSame('Ready', HardwareFulfilmentStepper::milestones()[10]);
    }

    public function test_readiness_advances_serial_through_ready(): void
    {
        $row = $this->row(HardwareFulfilmentOperationalStage::AwaitingSerial, true);
        $ready = new HardwareShipmentReadiness(
            canCreate: false,
            blockers: [],
            status: 'Not created',
            pickupBranch: null,
            pickupLocation: null,
            shipTo: null,
            parcel: null,
            invoice: null,
            serials: [],
            order: 'RDE1',
            product: 'MFS',
        );
        $this->assertSame(2, HardwareFulfilmentStepper::currentIndex($row, $ready));

        $ready = new HardwareShipmentReadiness(
            canCreate: false,
            blockers: [],
            status: 'Not created',
            pickupBranch: null,
            pickupLocation: null,
            shipTo: null,
            parcel: null,
            invoice: null,
            serials: ['10532319'],
            order: 'RDE1',
            product: 'MFS',
        );
        $this->assertSame(3, HardwareFulfilmentStepper::currentIndex($row, $ready));
    }

    private function row(HardwareFulfilmentOperationalStage $stage, bool $hasFulfilment): HardwareFulfilmentOperationalRow
    {
        return new HardwareFulfilmentOperationalRow(
            sourceId: 'RDE1',
            orderDateIst: '2026-09-07 10:00',
            customer: 'Buyer',
            product: 'MFS',
            sku: 'SKU',
            quantity: '1',
            payment: 'Paid',
            fulfilmentStatus: 'OPEN',
            serialStatus: 'None',
            invoiceStatus: 'None',
            shipmentStatus: 'None',
            awbStatus: 'None',
            stage: $stage,
            nextAction: 'Next',
            nextUrl: null,
            blocker: null,
            fulfilmentId: $hasFulfilment ? 1 : null,
            supportOrderId: 1,
            hasFulfilment: $hasFulfilment,
            source: 'RDE',
            section: HardwareOperationsSection::InProgress,
        );
    }
}
