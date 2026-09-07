<?php

namespace Tests\Unit\StatutoryInvoice;

use App\Services\StatutoryInvoice\ServiceSacResolver;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ServiceSacResolverTest extends TestCase
{
    public function test_rd_service_description_resolves_to_998313_even_when_spoke_sends_998314(): void
    {
        $sac = (new ServiceSacResolver)->resolve(
            'rdservice_in',
            null,
            'Information technology (IT) consulting & support services (SAC - 998313) - (Sr. No. 10500255) - 1 Year Unlimited',
            '998314',
        );

        $this->assertSame('998313', $sac);
    }

    public function test_rd_service_sku_resolves_to_998313(): void
    {
        $sac = (new ServiceSacResolver)->resolve(
            'rdservice_in',
            'RD-SVC',
            'Unrelated label',
            '998314',
        );

        $this->assertSame('998313', $sac);
    }

    public function test_unmatched_service_keeps_its_own_incoming_sac(): void
    {
        $sac = (new ServiceSacResolver)->resolve(
            'rdservice_in',
            'FUTURE-A',
            'Future Service A',
            '998399',
        );

        $this->assertSame('998399', $sac);
    }

    public function test_998314_is_not_a_generic_default_for_unrelated_services(): void
    {
        $sac = (new ServiceSacResolver)->resolve(
            'rdservice_in',
            null,
            'Unrelated consulting workshop',
            '998314',
        );

        $this->assertSame('998314', $sac);
    }

    public function test_hardware_hsn_is_not_rewritten_to_rd_service_sac(): void
    {
        $sac = (new ServiceSacResolver)->resolve(
            'radiumbox_com',
            'PMTMFS110Z',
            'MFS 110',
            '84716050',
        );

        $this->assertSame('84716050', $sac);
    }

    public function test_configured_future_service_uses_its_own_sac_not_998313(): void
    {
        config([
            'statutory_invoices.service_sac.future_service_a' => [
                'sac' => '998399',
                'channels' => ['rdservice_in'],
                'skus' => ['FUTURE-A'],
                'description_needles' => ['future service a'],
            ],
        ]);

        $resolver = new ServiceSacResolver;

        $this->assertSame('998399', $resolver->resolve(
            'rdservice_in',
            'FUTURE-A',
            'Future Service A',
            '998314',
        ));
        $this->assertSame('998313', $resolver->resolve(
            'rdservice_in',
            null,
            'RD Service',
            '998314',
        ));
    }

    public function test_ambiguous_service_match_fails_closed(): void
    {
        config([
            'statutory_invoices.service_sac.overlap' => [
                'sac' => '998399',
                'channels' => ['rdservice_in'],
                'description_needles' => ['rd service'],
            ],
        ]);

        $this->expectException(ValidationException::class);

        (new ServiceSacResolver)->resolve('rdservice_in', null, 'RD Service', '998314');
    }
}
