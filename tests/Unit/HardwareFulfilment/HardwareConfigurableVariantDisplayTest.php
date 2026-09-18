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
        $this->assertSame(
            'Mantra MFS 110 1R 1W U (bundled RD #1119)',
            HardwareConfigurableVariantDisplay::invoiceDescription($item, annotateBundledRd: true),
        );
    }

    public function test_item_110_2_3_c_uses_stored_fks(): void
    {
        $item = $this->item(946, 1121, 1125, 1724, 'Mantra MFS 100 / 110 L1 Fingerprint Scanner');

        $this->assertSame('Mantra MFS 110 2R 3W C', HardwareConfigurableVariantDisplay::forItem($item));
    }

    public function test_item_100_1_2_u_uses_stored_fks(): void
    {
        $item = $this->item(945, 989, 993, 995, 'Mantra MFS 100 / 110 L1 Fingerprint Scanner');

        $this->assertSame('Mantra MFS 100 1R 2W U', HardwareConfigurableVariantDisplay::forItem($item));
    }

    public function test_usb_plus_type_c_is_not_invented(): void
    {
        $item = $this->item(946, 1119, 1120, 1127, 'Mantra MFS 100 / 110 L1 Fingerprint Scanner');

        $this->assertNull(HardwareConfigurableVariantDisplay::forItem($item));
        $this->assertSame(
            'Mantra MFS 100 / 110 L1 Fingerprint Scanner',
            HardwareConfigurableVariantDisplay::label($item),
        );
    }

    public function test_incomplete_variant_keeps_existing_description(): void
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

    public function test_replacement_cable_model_ids_use_verified_static_labels(): void
    {
        $typeC = $this->item(1409, null, null, null, 'Biometric Replacement Cable');
        $usb = $this->item(1410, null, null, null, 'Biometric Replacement Cable');

        $this->assertSame('Mantra MFS110 Type-C', HardwareConfigurableVariantDisplay::label($typeC));
        $this->assertSame('Mantra MFS110 USB', HardwareConfigurableVariantDisplay::label($usb));
    }

    public function test_generic_replacement_cable_without_model_id_is_ambiguous(): void
    {
        $item = $this->item(0, null, null, null, 'Biometric Replacement Cable');
        $item->model_id = null;

        $this->assertSame('Exact variant unavailable', HardwareConfigurableVariantDisplay::label($item));
    }

    public function test_handoff_variant_field_is_preferred_over_generic_description(): void
    {
        $item = $this->item(9999, null, null, null, 'Biometric Replacement Cable');
        $item->variant = 'Mantra MFS110 USB';

        $this->assertSame('Mantra MFS110 USB', HardwareConfigurableVariantDisplay::label($item));
    }

    public function test_verified_token_and_camera_model_ids(): void
    {
        $this->assertSame(
            'Feitian ePass HYP2003 Auto USB Token',
            HardwareConfigurableVariantDisplay::label($this->item(347, null, null, null, 'USB Token')),
        );
        $this->assertSame(
            'BioEnable C600 Face Camera · C600',
            HardwareConfigurableVariantDisplay::label($this->item(1749, null, null, null, 'BioEnable C600')),
        );
    }

    private function item(
        int $modelId,
        ?int $rdserviceid,
        ?int $amcid,
        ?int $otgid,
        string $description,
    ): CommerceOrderItem {
        $item = new CommerceOrderItem;
        $item->model_id = $modelId;
        $item->rdserviceid = $rdserviceid;
        $item->amcid = $amcid;
        $item->otgid = $otgid;
        $item->description = $description;

        return $item;
    }
}
