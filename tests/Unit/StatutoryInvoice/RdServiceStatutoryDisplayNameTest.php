<?php

namespace Tests\Unit\StatutoryInvoice;

use App\Models\ServiceCategory;
use App\Models\ServiceItem;
use App\Services\StatutoryInvoice\RdServiceStatutoryDisplayName;
use Tests\TestCase;

class RdServiceStatutoryDisplayNameTest extends TestCase
{
    public function test_canonical_name_and_sac_are_configured_separately(): void
    {
        $this->assertSame('IT Consulting & Support Service', RdServiceStatutoryDisplayName::canonical());
        $this->assertSame('998313', config('statutory_invoices.service_sac.rd_service.sac'));
    }

    public function test_legacy_channel_description_is_normalized_without_sac_suffix(): void
    {
        $legacy = 'Information technology (IT) consulting & support services (SAC - 998313) - (Sr. No. 10500255) - 1 Year Unlimited';

        $normalized = RdServiceStatutoryDisplayName::normalizeInvoiceDescription($legacy);

        $this->assertSame(
            'IT Consulting & Support Service - (Sr. No. 10500255) - 1 Year Unlimited',
            $normalized,
        );
        $this->assertStringNotContainsString('SAC - 998313', $normalized);
        $this->assertStringNotContainsString('information technology', strtolower($normalized));
    }

    public function test_non_rd_service_description_is_unchanged(): void
    {
        $description = 'Future Service A';

        $this->assertSame($description, RdServiceStatutoryDisplayName::normalizeInvoiceDescription($description));
    }

    public function test_rd_service_catalog_item_uses_canonical_display_name(): void
    {
        $category = new ServiceCategory([
            'code' => 'rd_service',
            'name' => 'RD Service',
        ]);

        $item = new ServiceItem([
            'name' => 'Information technology (IT) consulting & support services (SAC - 998313)',
            'sac_code' => '998313',
        ]);
        $item->setRelation('category', $category);

        $this->assertSame('IT Consulting & Support Service', RdServiceStatutoryDisplayName::catalogName($item));
    }
}
