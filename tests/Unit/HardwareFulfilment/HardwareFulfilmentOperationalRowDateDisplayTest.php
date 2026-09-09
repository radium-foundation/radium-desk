<?php

namespace Tests\Unit\HardwareFulfilment;

use App\Enums\HardwareFulfilmentOperationalStage;
use App\Enums\HardwareOperationsSection;
use App\Services\HardwareFulfilment\Data\HardwareFulfilmentOperationalRow;
use Tests\TestCase;

class HardwareFulfilmentOperationalRowDateDisplayTest extends TestCase
{
    public function test_formats_existing_ist_sort_field_without_seconds(): void
    {
        $row = $this->row('2026-09-09 20:18');

        $this->assertSame('09 Sep · 8:18 PM', $row->orderDateDisplay());
        $this->assertSame('09 Sep 8:18 PM', $row->orderDateDisplayCompact());
        $this->assertSame('2026-09-09 20:18', $row->orderDateIst);
        $this->assertStringNotContainsString(':18:', $row->orderDateDisplay());
        $this->assertStringNotContainsString('2026-09-09', $row->orderDateDisplay());
    }

    public function test_missing_timestamp_stays_an_em_dash(): void
    {
        $row = $this->row('—');

        $this->assertSame('—', $row->orderDateDisplay());
        $this->assertSame('—', $row->orderDateDisplayCompact());
    }

    public function test_unparseable_timestamp_is_returned_unchanged(): void
    {
        $row = $this->row('not-a-date');

        $this->assertSame('not-a-date', $row->orderDateDisplay());
        $this->assertSame('not-a-date', $row->orderDateDisplayCompact());
    }

    private function row(string $orderDateIst): HardwareFulfilmentOperationalRow
    {
        return new HardwareFulfilmentOperationalRow(
            sourceId: 'RDE318516',
            orderDateIst: $orderDateIst,
            customer: 'Buyer',
            product: 'MFS',
            sku: 'SKU',
            quantity: '1',
            payment: 'Paid',
            fulfilmentStatus: 'READY FOR FULFILMENT',
            serialStatus: 'Not allocated',
            invoiceStatus: 'None',
            shipmentStatus: 'None',
            awbStatus: 'None',
            stage: HardwareFulfilmentOperationalStage::AwaitingSerial,
            nextAction: 'Allocate Serial',
            nextUrl: null,
            blocker: null,
            fulfilmentId: 1,
            supportOrderId: 1,
            hasFulfilment: true,
            source: 'RDE',
            section: HardwareOperationsSection::InProgress,
        );
    }
}
