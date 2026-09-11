<?php

namespace Tests\Unit\HardwareFulfilment;

use App\Enums\HardwareFulfilmentOperationalStage;
use App\Enums\HardwareOperationsSection;
use App\Services\HardwareFulfilment\Data\HardwareFulfilmentOperationalRow;
use Tests\TestCase;

class HardwareFulfilmentOperationalRowDatetimeTest extends TestCase
{
    public function test_compact_timeline_same_calendar_date(): void
    {
        $row = $this->row('2026-09-10 19:16', '2026-09-10 19:22');

        $this->assertSame('10 Sep 07:16 PM L 07:22 PM', $row->compactTimelineDisplay());
        $this->assertStringContainsString('2026-09-10 19:16 IST', $row->compactTimelineTitle());
        $this->assertStringContainsString('2026-09-10 19:22 IST', $row->compactTimelineTitle());
    }

    public function test_compact_timeline_different_calendar_dates(): void
    {
        $row = $this->row('2026-09-10 19:16', '2026-09-11 19:22');

        $this->assertSame('10 Sep 07:16 PM L 11 Sep 07:22 PM', $row->compactTimelineDisplay());
    }

    public function test_compact_timeline_missing_order_renders_dash(): void
    {
        $row = $this->row('—', '2026-09-10 19:22');

        $this->assertSame('—', $row->compactTimelineDisplay());
    }

    public function test_compact_timeline_missing_last_action_shows_order_only(): void
    {
        $row = $this->row('2026-09-10 19:16', '—');

        $this->assertSame('10 Sep 07:16 PM', $row->compactTimelineDisplay());
    }

    public function test_order_and_last_action_use_ist_labels_even_when_identical(): void
    {
        $row = $this->row('2026-09-10 19:16', '2026-09-10 19:16');

        $this->assertSame('10 Sep · 7:16 PM', $row->orderDateDisplay());
        $this->assertSame('10 Sep · 7:16 PM', $row->lastActionDateDisplay());
        $this->assertSame('2026-09-10 19:16 IST', $row->orderDateTitle());
        $this->assertSame('2026-09-10 19:16 IST', $row->lastActionDateTitle());
    }

    public function test_last_action_can_differ_from_order_time(): void
    {
        $row = $this->row('2026-09-10 10:00', '2026-09-10 19:16');

        $this->assertSame('10 Sep · 10:00 AM', $row->orderDateDisplay());
        $this->assertSame('10 Sep · 7:16 PM', $row->lastActionDateDisplay());
    }

    public function test_missing_timestamps_render_dash(): void
    {
        $row = $this->row('—', '—');

        $this->assertSame('—', $row->orderDateDisplay());
        $this->assertSame('—', $row->lastActionDateDisplay());
        $this->assertSame('—', $row->compactTimelineDisplay());
    }

    private function row(string $orderDateIst, string $lastActionDateIst): HardwareFulfilmentOperationalRow
    {
        return new HardwareFulfilmentOperationalRow(
            sourceId: 'RDE318516',
            orderDateIst: $orderDateIst,
            lastActionDateIst: $lastActionDateIst,
            customer: 'Buyer',
            product: 'Mantra MFS 110 1R 1W U',
            sku: 'SKU',
            quantity: '1',
            payment: 'Paid',
            fulfilmentStatus: 'READY',
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
            section: HardwareOperationsSection::NeedsFulfilment,
        );
    }
}
