<?php

namespace Tests\Unit\StatutoryInvoice;

use App\Services\StatutoryInvoice\ServiceStatutoryClassification;
use Tests\TestCase;

class ServiceStatutoryClassificationTest extends TestCase
{
    public function test_rd_service_sac_998313_maps_to_is_servc_y_and_uqc_oth(): void
    {
        $profile = (new ServiceStatutoryClassification)->profileForCommerceLine(
            'rdservice_in',
            null,
            'Information technology (IT) consulting & support services (SAC - 998313)',
            '998313',
        );

        $this->assertNotNull($profile);
        $this->assertSame('998313', $profile->sac);
        $this->assertSame('Y', $profile->isServc);
        $this->assertSame('OTH', $profile->uqc);
    }

    public function test_legacy_sac_998314_maps_to_998313_profile(): void
    {
        $profile = (new ServiceStatutoryClassification)->profileForCommerceLine(
            'rdservice_in',
            null,
            'Information technology (IT) consulting & support services (SAC - 998313)',
            '998314',
        );

        $this->assertNotNull($profile);
        $this->assertSame('998313', $profile->sac);
        $this->assertSame('OTH', $profile->uqc);
    }

    public function test_hardware_hsn_does_not_receive_service_uqc(): void
    {
        $profile = (new ServiceStatutoryClassification)->profileForCommerceLine(
            'radiumbox_com',
            '946',
            'Hardware line',
            '84716050',
        );

        $this->assertNull($profile);
    }
}
