<?php

namespace Tests\Unit\HardwareFulfilment;

use App\Enums\HardwareFulfilmentOperationalStage;
use App\Enums\HardwareOperationsSection;
use App\Enums\HardwareWorkspaceFilter;
use App\Services\HardwareFulfilment\Data\HardwareFulfilmentOperationalRow;
use Tests\TestCase;

class HardwareFulfilmentOperationalRowWorkspaceTest extends TestCase
{
    public function test_shipped_workspace_item_when_stage_completed(): void
    {
        $row = $this->row(stage: HardwareFulfilmentOperationalStage::Completed);

        $this->assertTrue($row->isShippedWorkspaceItem());
    }

    public function test_active_workspace_item_when_not_completed(): void
    {
        $row = $this->row(stage: HardwareFulfilmentOperationalStage::AwaitingSerial);

        $this->assertFalse($row->isShippedWorkspaceItem());
    }

    public function test_ready_filter_excludes_scheduled_awaiting_fulfilment(): void
    {
        $ready = $this->row(stage: HardwareFulfilmentOperationalStage::AwaitingSerial);
        $scheduled = $this->row(stage: HardwareFulfilmentOperationalStage::AwaitingFulfilment);

        $this->assertTrue($ready->matchesWorkspaceFilter(HardwareWorkspaceFilter::Ready));
        $this->assertFalse($scheduled->matchesWorkspaceFilter(HardwareWorkspaceFilter::Ready));
        $this->assertTrue($scheduled->matchesWorkspaceFilter(HardwareWorkspaceFilter::Scheduled));
    }

    public function test_exceptions_filter_matches_blocked_review_stage(): void
    {
        $row = $this->row(stage: HardwareFulfilmentOperationalStage::BlockedReview);

        $this->assertTrue($row->matchesWorkspaceFilter(HardwareWorkspaceFilter::Exceptions));
        $this->assertFalse($row->matchesWorkspaceFilter(HardwareWorkspaceFilter::Pickup));
    }

    public function test_pickup_filter_matches_ready_for_pickup_stage(): void
    {
        $row = $this->row(stage: HardwareFulfilmentOperationalStage::ReadyForPickup);

        $this->assertTrue($row->matchesWorkspaceFilter(HardwareWorkspaceFilter::Pickup));
    }

    public function test_b2b_flag_is_exposed_on_row(): void
    {
        $b2b = $this->row(isB2bCustomer: true);
        $b2c = $this->row(isB2bCustomer: false);

        $this->assertTrue($b2b->isB2bCustomer);
        $this->assertFalse($b2c->isB2bCustomer);
    }

    private function row(
        HardwareFulfilmentOperationalStage $stage = HardwareFulfilmentOperationalStage::AwaitingSerial,
        bool $isB2bCustomer = false,
    ): HardwareFulfilmentOperationalRow {
        return new HardwareFulfilmentOperationalRow(
            sourceId: 'RDE318516',
            orderDateIst: '2026-09-10 19:16',
            lastActionDateIst: '2026-09-10 19:22',
            customer: 'RAMESH KUMAR',
            product: 'Mantra MFS 110 1R 1W U',
            sku: 'SKU',
            quantity: '1',
            payment: 'Paid',
            fulfilmentStatus: 'READY',
            serialStatus: 'Not allocated',
            invoiceStatus: 'None',
            shipmentStatus: 'None',
            awbStatus: 'None',
            stage: $stage,
            nextAction: 'Allocate Serial',
            nextUrl: null,
            blocker: null,
            fulfilmentId: 1,
            supportOrderId: 1,
            hasFulfilment: true,
            source: 'RDE',
            section: HardwareOperationsSection::NeedsFulfilment,
            isB2bCustomer: $isB2bCustomer,
        );
    }
}
