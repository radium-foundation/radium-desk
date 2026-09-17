<?php

namespace Tests\Unit\ServicePos;

use App\Support\ServicePos\AdminCatalogImportMapper;
use PHPUnit\Framework\TestCase;

class AdminCatalogImportMapperTest extends TestCase
{
    public function test_maps_admin_digital_product_fields(): void
    {
        $mapper = new AdminCatalogImportMapper();

        $mapped = $mapper->mapProductRow([
            'id' => 1087,
            'attribute_id' => 1,
            'product_id' => 946,
            'sku_code' => null,
            'product_name' => 'StarTek FM220 RD Service',
            'short_name' => '1 Year Unlimited',
            'hsn_code' => '998313',
            'gst_percentage' => 18,
            'selling_price' => 310.17,
            'publish_price' => 366,
            'product_type' => 'Digital',
            'status' => 1,
            'rdservice' => 1,
        ]);

        $this->assertSame(1087, $mapped['legacy_admin_product_id']);
        $this->assertSame('rd_service', $mapped['category_code']);
        $this->assertSame('998313', $mapped['sac_code']);
        $this->assertFalse($mapper->requiresManualReview($mapped));
    }

    public function test_flags_missing_sac_for_manual_review(): void
    {
        $mapper = new AdminCatalogImportMapper();

        $mapped = $mapper->mapProductRow([
            'id' => 2000,
            'attribute_id' => 1,
            'product_name' => 'Incomplete Service',
            'hsn_code' => null,
            'status' => 1,
        ]);

        $this->assertTrue($mapper->requiresManualReview($mapped));
    }
}
