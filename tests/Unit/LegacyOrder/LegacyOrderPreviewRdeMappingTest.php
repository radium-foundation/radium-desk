<?php

namespace Tests\Unit\LegacyOrder;

use App\Data\LegacyOrderPreview;
use App\Services\RadiumBox\RadiumBoxOrderEnrichment;
use Tests\TestCase;

class LegacyOrderPreviewRdeMappingTest extends TestCase
{
    public function test_legacy_preview_exposes_rde_product_and_serial_for_import(): void
    {
        $preview = LegacyOrderPreview::fromEnrichment(
            'RDE177816',
            new RadiumBoxOrderEnrichment(
                serialNumber: '10024774',
                deviceModel: 'Mantra MFS 100 / 110 L1 Fingerprint Scanner',
                customerName: 'Pallab Mukherjee',
                customerPhone: '9874773752',
                legacyOrderStatus: 'Shipped',
            ),
        );

        $this->assertSame('RDE177816', $preview->orderId);
        $this->assertSame('Mantra MFS 100 / 110 L1 Fingerprint Scanner', $preview->productModel);
        $this->assertSame('10024774', $preview->serialNumber);
        $this->assertTrue($preview->isCompleteForOneClick());
        $this->assertSame([], $preview->missingFieldsForOneClick());
    }
}
