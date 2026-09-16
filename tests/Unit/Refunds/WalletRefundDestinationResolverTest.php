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
        $this->assertTrue($resolver->isRadiumBox('RB33'));
        $this->assertTrue($resolver->isRadiumBox('RBP1'));
        $this->assertFalse($resolver->isRdServiceIn('RDE318801'));
    }

    public function test_net_family_is_neither_rdservice_in_nor_radiumbox(): void
    {
        $resolver = new WalletRefundDestinationResolver;

        foreach (['RA3506948', 'RN1', 'RNP1'] as $orderId) {
            $this->assertSame('rdservice.net', $resolver->owner($orderId));
            $this->assertFalse($resolver->isRdServiceIn($orderId));
            $this->assertFalse($resolver->isRadiumBox($orderId));
        }
    }

    public function test_unknown_prefix_has_no_wallet_owner(): void
    {
        $resolver = new WalletRefundDestinationResolver;

        $this->assertNull($resolver->owner('XX12345'));
        $this->assertFalse($resolver->isRdServiceIn('XX12345'));
        $this->assertFalse($resolver->isRadiumBox('XX12345'));
    }
}
