<?php

namespace Tests\Feature\HardwareFulfilment;

use App\Support\HardwareFulfilment\HardwareAllocatedSerialDisplay;
use Tests\TestCase;

class HardwareFulfilmentSerialSummaryViewTest extends TestCase
{
    public function test_qty_one_view_is_copyable_without_remainder(): void
    {
        $html = view('inventory.hardware-fulfilments.fragments.serial-summary', [
            'serials' => ['10532347'],
            'expected' => 1,
        ])->render();

        $this->assertStringContainsString('10532347', $html);
        $this->assertStringNotContainsString('+0', $html);
        $this->assertStringNotContainsString('Copy All', $html);
        $this->assertStringContainsString('data-copyable-identifier', $html);
    }

    public function test_qty_ten_view_lists_every_serial_and_copy_all(): void
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
        $html = view('inventory.hardware-fulfilments.fragments.serial-summary', [
            'serials' => $serials,
            'expected' => 10,
        ])->render();

        $this->assertStringContainsString('10532347 +9', $html);
        $this->assertStringContainsString('Allocated Serials (10)', $html);
        $this->assertStringContainsString('Quantity 10 · 10 allocated', $html);
        $this->assertStringContainsString('Copy All', $html);
        $this->assertStringContainsString(HardwareAllocatedSerialDisplay::copyValue($serials), $html);
        $this->assertStringContainsString('Copied 10 serials', $html);
        foreach ($serials as $serial) {
            $this->assertStringContainsString($serial, $html);
        }
        $this->assertSame(10, substr_count($html, '<li class="font-monospace user-select-all">'));
    }

    public function test_mismatch_view_names_the_discrepancy(): void
    {
        $html = view('inventory.hardware-fulfilments.fragments.serial-summary', [
            'serials' => ['10532347', '10556040'],
            'expected' => 10,
        ])->render();

        $this->assertStringContainsString('Serials: 2 / 10 allocated', $html);
        $this->assertStringNotContainsString('10532347 +1', $html);
        $this->assertStringContainsString('10532347', $html);
        $this->assertStringContainsString('10556040', $html);
        $this->assertStringContainsString('Copy All', $html);
    }

    public function test_more_serials_than_quantity_is_named_as_a_discrepancy(): void
    {
        $html = view('inventory.hardware-fulfilments.fragments.serial-summary', [
            'serials' => ['10532347', '10556040', '10561005'],
            'expected' => 2,
        ])->render();

        $this->assertStringContainsString('Serials: 3 / 2 allocated', $html);
        $this->assertStringNotContainsString('10532347 +2', $html);
        $this->assertStringContainsString('Copy All', $html);
    }
}
