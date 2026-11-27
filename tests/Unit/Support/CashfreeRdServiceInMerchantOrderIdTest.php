<?php

namespace Tests\Unit\Support;

use App\Support\BusinessOrderId;
use App\Support\CashfreeRdServiceInMerchantOrderId;
use Tests\TestCase;

class CashfreeRdServiceInMerchantOrderIdTest extends TestCase
{
    public function test_provider_id_canonicalizes_to_rd_business_id(): void
    {
        $this->assertSame('RD44162', CashfreeRdServiceInMerchantOrderId::toCanonicalBusinessOrderId('RDI-RD44162'));
        $this->assertSame('RDI-RD44162', CashfreeRdServiceInMerchantOrderId::rawProviderOrderId('RDI-RD44162'));
        $this->assertSame('RDI-RD44162', CashfreeRdServiceInMerchantOrderId::cashfreeMerchantOrderIdForApi('RDI-RD44162'));
    }

    public function test_canonical_desk_id_derives_provider_for_cashfree_api(): void
    {
        $this->assertSame('RDI-RD44162', CashfreeRdServiceInMerchantOrderId::cashfreeMerchantOrderIdForApi('RD44162'));
        $this->assertSame('RDI-RD44162', CashfreeRdServiceInMerchantOrderId::rawProviderOrderId('RD44162'));
    }

    public function test_webhook_create_persists_canonical_business_id(): void
    {
        $this->assertSame('RD44162', CashfreeRdServiceInMerchantOrderId::deskBusinessOrderIdForWebhookCreate('RDI-RD44162'));
    }

    public function test_rdi_provider_parses_as_rdservice_in_service(): void
    {
        $this->assertTrue(BusinessOrderId::isRdServiceInService('RDI-RD44162'));
        $this->assertSame('rdservice.in', BusinessOrderId::owner('RDI-RD44162'));
    }
}
