<?php

namespace Tests\Unit\HardwareFulfilment;

use App\Enums\HardwareFulfilmentOperationalStage;
use App\Enums\HardwareOperationsSection;
use App\Services\HardwareFulfilment\Data\HardwareFulfilmentOperationalRow;
use Tests\TestCase;

class HardwareFulfilmentOperationalRowSerialDisplayTest extends TestCase
{
    public function test_qty_one_does_not_add_a_remainder(): void
    {
        $row = $this->row(['10532347'], 1);

        $this->assertSame('10532347', $row->serialDisplay());
        $this->assertSame(['10532347'], $row->allocatedSerials());
        $this->assertTrue($row->serialsComplete());
        $this->assertStringNotContainsString('+0', $row->serialDisplay());
    }

    public function test_qty_ten_keeps_every_serial_behind_the_compact_label(): void
    {
        $serials = [
            '10532347',
            '10556040',
            '10561005',
            '10553573',
            '10556002',
            '10532464',
            '10566561',
            '10566466',
            '10562862',
            '10553763',
        ];
        $row = $this->row($serials, 10);

        $this->assertSame('10532347 +9', $row->serialDisplay());
        $this->assertSame($serials, $row->allocatedSerials());
        $this->assertTrue($row->serialsComplete());
        $this->assertSame(10, $row->expectedSerialQuantity);
    }

    public function test_mismatch_is_not_presented_as_complete(): void
    {
        $short = $this->row(['10532347', '10556040'], 10);
        $this->assertSame('Serials: 2 / 10 allocated', $short->serialDisplay());
        $this->assertFalse($short->serialsComplete());

        $extra = $this->row(['A', 'B', 'C'], 2);
        $this->assertSame('Serials: 3 / 2 allocated', $extra->serialDisplay());
        $this->assertFalse($extra->serialsComplete());
    }

    public function test_comma_separated_status_is_not_truncated_to_the_first_serial(): void
    {
        $row = $this->row([], 2, '10532347, 10556040');

        $this->assertSame(['10532347', '10556040'], $row->allocatedSerials());
        $this->assertSame('10532347 +1', $row->serialDisplay());
    }

    /**
     * @param  list<string>  $serials
     */
    private function row(array $serials, ?int $expected, string $serialStatus = 'Not allocated'): HardwareFulfilmentOperationalRow
    {
        return new HardwareFulfilmentOperationalRow(
            sourceId: 'RDE318516',
            orderDateIst: '2026-09-08 10:00',
            customer: 'Buyer',
            product: 'MFS',
            sku: 'SKU',
            quantity: $expected !== null ? (string) $expected : '—',
            payment: 'Paid',
            fulfilmentStatus: 'INVOICE ISSUED',
            serialStatus: $serialStatus,
            invoiceStatus: 'INV-076724',
            shipmentStatus: 'None',
            awbStatus: 'None',
            stage: HardwareFulfilmentOperationalStage::ReadyForShipment,
            nextAction: 'Enter Package Dimensions',
            nextUrl: null,
            blocker: null,
            fulfilmentId: 15,
            supportOrderId: 1,
            hasFulfilment: true,
            source: 'RDE',
            section: HardwareOperationsSection::InProgress,
            allocatedSerialNumbers: $serials,
            expectedSerialQuantity: $expected,
        );
    }
}
