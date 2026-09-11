<?php

namespace Tests\Unit\HardwareFulfilment;

use App\Models\CommerceOrderItem;
use App\Support\HardwareFulfilment\HardwareConfigurableVariantDisplay;
use Tests\TestCase;

class HardwareConfigurableVariantDisplayTest extends TestCase
{
    public function test_format_is_deterministic_for_mantra_mfs(): void
    {
        $this->assertSame(
            'Mantra MFS 110 1R 1W U',
            HardwareConfigurableVariantDisplay::format('Mantra MFS', '110', 1, 1, 'U'),
        );
        $this->assertSame(
            'Mantra MFS 110 2R 3W C',
            HardwareConfigurableVariantDisplay::format('Mantra MFS', '110', 2, 3, 'C'),
        );
        $this->assertSame(
            'Mantra MFS 100 1R 2W U',
            HardwareConfigurableVariantDisplay::format('Mantra MFS', '100', 1, 2, 'U'),
        );
    }

    public function test_item_110_1_1_u_uses_stored_fks(): void
    {
        $item = $this->item(946, 1119, 1120, 1126, 'Mantra MFS 100 / 110 L1 Fingerprint Scanner');

        $this->assertSame('Mantra MFS 110 1R 1W U', HardwareConfigurableVariantDisplay::forItem($item));
        $this->assertSame('Mantra MFS 110 1R 1W U', HardwareConfigurableVariantDisplay::label($item));
    }

    public function test_item_110_usb_plus_type_c_uses_verified_otg_mapping(): void
    {
        $item = $this->item(946, 1119, 1120, 1127, 'Mantra MFS 100 / 110 L1 Fingerprint Scanner');

        $this->assertSame('Mantra MFS 110 1R 1W UC', HardwareConfigurableVariantDisplay::forItem($item));
        $this->assertSame('Mantra MFS 110 1R 1W UC', HardwareConfigurableVariantDisplay::label($item));
    }

    public function test_workspace_line_for_rbp31_style_item_is_operationally_explicit(): void
    {
        $item = $this->item(946, 1119, 1120, 1127, 'Mantra MFS 100 / 110 L1 Fingerprint Scanner', 1);
        $line = HardwareConfigurableVariantDisplay::workspaceLine($item);

        $this->assertSame('Mantra MFS 110 · L1', $line['primary']);
        $this->assertStringContainsString('RD 1Y', $line['secondary']);
        $this->assertStringContainsString('Warranty 1Y', $line['secondary']);
        $this->assertStringContainsString('USB + Type-C', $line['secondary']);
        $this->assertStringContainsString('Qty 1', $line['secondary']);
        $this->assertFalse($line['ambiguous']);
        $this->assertStringContainsString('RD Level: L1', $line['title']);
    }

    public function test_item_100_1_2_u_uses_stored_fks(): void
    {
        $item = $this->item(945, 989, 993, 995, 'Mantra MFS 100 / 110 L1 Fingerprint Scanner');

        $this->assertSame('Mantra MFS 100 1R 2W U', HardwareConfigurableVariantDisplay::forItem($item));
    }

    public function test_ambiguous_marketing_description_is_not_shown_as_exact_variant(): void
    {
        $item = $this->item(946, null, null, null, 'Mantra MFS 100 / 110 L1 Fingerprint Scanner');

        $this->assertNull(HardwareConfigurableVariantDisplay::forItem($item));
        $this->assertSame('Exact variant unavailable', HardwareConfigurableVariantDisplay::label($item));

        $line = HardwareConfigurableVariantDisplay::workspaceLine($item);
        $this->assertTrue($line['ambiguous']);
        $this->assertSame('Exact variant unavailable', $line['primary']);
    }

    public function test_incomplete_variant_keeps_existing_description_for_invoice_annotation(): void
    {
        $item = $this->item(946, 1119, null, null, 'Mantra MFS 100 / 110 L1 Fingerprint Scanner');

        $this->assertNull(HardwareConfigurableVariantDisplay::forItem($item));
        $this->assertSame(
            'Mantra MFS 100 / 110 L1 Fingerprint Scanner (bundled RD #1119)',
            HardwareConfigurableVariantDisplay::invoiceDescription($item, annotateBundledRd: true),
        );
    }

    public function test_non_mfs_model_is_unchanged(): void
    {
        $item = $this->item(951, 88, null, null, 'MSO1300');

        $this->assertNull(HardwareConfigurableVariantDisplay::forItem($item));
        $this->assertSame('MSO1300', HardwareConfigurableVariantDisplay::label($item));
    }

    private function item(
        int $modelId,
        ?int $rdserviceid,
        ?int $amcid,
        ?int $otgid,
        string $description,
        ?int $qty = null,
    ): CommerceOrderItem {
        $item = new CommerceOrderItem;
        $item->model_id = $modelId;
        $item->rdserviceid = $rdserviceid;
        $item->amcid = $amcid;
        $item->otgid = $otgid;
        $item->description = $description;
        $item->qty = $qty;

        return $item;
    }
}
