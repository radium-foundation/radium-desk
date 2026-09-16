<?php

namespace Tests\Unit\Refunds;

use App\Services\Refunds\WalletRefundDestinationResolver;
use Tests\TestCase;

class WalletRefundDestinationResolverTest extends TestCase
{
    public function test_rd_family_routes_to_rdservice_in(): void
    {
        $resolver = new WalletRefundDestinationResolver;

        $this->assertTrue($resolver->isRdServiceIn('RD3437407'));
        $this->assertTrue($resolver->isRdServiceIn('RDP1'));
        $this->assertTrue($resolver->isRdServiceIn('RIN3512344'));
        $this->assertFalse($resolver->isRadiumBox('RD3437407'));
    }

    public function test_box_family_routes_to_radiumbox(): void
    {
        $resolver = new WalletRefundDestinationResolver;

        $this->assertTrue($resolver->isRadiumBox('RDE318801'));
        $this->assertTrue($resolver->isRadiumBox('RBX3511545'));
        $this->assertFalse($resolver->isRdServiceIn('RDE318801'));
    }
}
