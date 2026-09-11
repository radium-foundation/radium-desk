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

    public function test_workspace_line_uses_compact_operational_tokens(): void
    {
        $item = $this->item(946, 1119, 1120, 1127, 'Mantra MFS 100 / 110 L1 Fingerprint Scanner', 1);
        $line = HardwareConfigurableVariantDisplay::workspaceLine($item);

        $this->assertSame('Mantra MFS 110 L1', $line['primary']);
        $this->assertSame('R1 W1 UC Q1', $line['secondary']);
        $this->assertFalse($line['ambiguous']);
        $this->assertStringContainsString("Mantra MFS 110 L1\n", $line['title']);
        $this->assertStringContainsString('RD Service       1 Year (R1)', $line['title']);
        $this->assertStringContainsString('Warranty         1 Year (W1)', $line['title']);
        $this->assertStringContainsString('USB / OTG        USB + Type-C (UC)', $line['title']);
        $this->assertStringContainsString('Quantity         1 (Q1)', $line['title']);
        $this->assertStringNotContainsString('1R =', $line['title']);
    }

    public function test_workspace_line_mfs_100_l0_compact_tokens(): void
    {
        $item = $this->item(945, 989, 993, 995, 'Mantra MFS 100 / 110 L1 Fingerprint Scanner', 1);
        $line = HardwareConfigurableVariantDisplay::workspaceLine($item);

        $this->assertSame('Mantra MFS 100 L0', $line['primary']);
        $this->assertSame('R1 W2 U Q1', $line['secondary']);
    }

    public function test_workspace_line_supports_multi_year_and_usb_c_tokens(): void
    {
        $item = $this->item(946, 1121, 1125, 1724, 'Mantra MFS 100 / 110 L1 Fingerprint Scanner', 1);
        $line = HardwareConfigurableVariantDisplay::workspaceLine($item);

        $this->assertSame('Mantra MFS 110 L1', $line['primary']);
        $this->assertSame('R2 W3 C Q1', $line['secondary']);
        $this->assertStringContainsString('USB / OTG        USB-C (C)', $line['title']);
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

    public function test_rbp29_ugr89_resolves_from_model_id_not_marketing_description(): void
    {
        $item = $this->item(
            1723,
            null,
            1788,
            null,
            'Radium Box UGR 86 UIDAI Approved USB GPS Receiver for AADHAAR',
            1,
        );
        $line = HardwareConfigurableVariantDisplay::workspaceLine($item);

        $this->assertSame('Radium Box UGR 89', $line['primary']);
        $this->assertSame('NaviC Q1', $line['secondary']);
        $this->assertStringContainsString('Chipset          NaviC', $line['title']);
        $this->assertFalse($line['ambiguous']);
        $this->assertStringNotContainsString('UGR 86', $line['primary']);
    }

    public function test_ugr86_model_resolves_from_model_id(): void
    {
        $item = $this->item(926, null, null, null, 'Radium Box UGR 86 UIDAI Approved USB GPS Receiver for AADHAAR', 1);
        $line = HardwareConfigurableVariantDisplay::workspaceLine($item);

        $this->assertSame('Radium Box UGR 86', $line['primary']);
        $this->assertSame('Q1', $line['secondary']);
    }

    public function test_rbp24_iris_resolves_from_model_id_with_shared_option_fks(): void
    {
        $item = $this->item(
            1006,
            1130,
            1133,
            1136,
            'Mantra Iris Scanner - Single USB MIS 100 V2 Biometric Device',
            1,
        );
        $line = HardwareConfigurableVariantDisplay::workspaceLine($item);

        $this->assertSame('Mantra Iris MIS 100 V2', $line['primary']);
        $this->assertSame('W2 U Q1', $line['secondary']);
        $this->assertStringContainsString('Warranty         2 Years (W2)', $line['title']);
    }

    public function test_ambiguous_ugr_marketing_description_without_model_fk(): void
    {
        $item = new CommerceOrderItem;
        $item->description = 'Radium Box UGR86/ UGR89 UIDAI Approved USB GPS Receiver for AADHAAR';

        $line = HardwareConfigurableVariantDisplay::workspaceLine($item);

        $this->assertTrue($line['ambiguous']);
        $this->assertSame('Exact variant unavailable', $line['primary']);
    }

    public function test_non_mfs_model_uses_catalog_identity(): void
    {
        $item = $this->item(951, null, null, null, 'MSO1300');

        $this->assertNull(HardwareConfigurableVariantDisplay::forItem($item));
        $this->assertSame('MSO 1300 E3 L1', HardwareConfigurableVariantDisplay::label($item));
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
