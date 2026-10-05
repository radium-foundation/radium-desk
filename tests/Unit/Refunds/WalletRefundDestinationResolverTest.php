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
            $this->assertSame(WalletRefundDestinationResolver::RDSERVICE_NET, $resolver->owner($orderId));
            $this->assertTrue($resolver->isRdServiceNet($orderId));
            $this->assertFalse($resolver->isRdServiceIn($orderId));
            $this->assertFalse($resolver->isRadiumBox($orderId));
            $this->assertFalse($resolver->supportsAutomatedWalletCredit($orderId));
        }
    }

    public function test_supported_wallet_destinations_remain_rdservice_in_and_radiumbox(): void
    {
        $resolver = new WalletRefundDestinationResolver;

        $this->assertTrue($resolver->supportsAutomatedWalletCredit('RD3437407'));
        $this->assertTrue($resolver->supportsAutomatedWalletCredit('RB403'));
        $this->assertFalse($resolver->supportsAutomatedWalletCredit('RN92'));
    }

    public function test_rdservice_net_supports_automated_wallet_credit_only_when_configured(): void
    {
        config([
            'rdservice_net.wallet_refund_credit_enabled' => true,
            'order_lookup.spokes.rdservice_net.enabled' => true,
            'order_lookup.spokes.rdservice_net.base_url' => 'https://rdservice.net.test',
            'order_lookup.spokes.rdservice_net.token' => 'net-token',
        ]);

        $resolver = new WalletRefundDestinationResolver;

        $this->assertTrue($resolver->supportsAutomatedWalletCredit('RN92'));
        $this->assertTrue($resolver->isRdServiceNetWalletRefundConfigured());
    }

    public function test_unknown_prefix_has_no_wallet_owner(): void
    {
        $resolver = new WalletRefundDestinationResolver;

        $this->assertNull($resolver->owner('XX12345'));
        $this->assertFalse($resolver->isRdServiceIn('XX12345'));
        $this->assertFalse($resolver->isRadiumBox('XX12345'));
    }
}
