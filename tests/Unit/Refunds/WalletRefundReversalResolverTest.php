<?php

namespace Tests\Unit\Refunds;

use App\Services\RadiumBox\RadiumBoxWalletRefundReversalClient;
use App\Services\RdService\RdServiceInWalletRefundReversalClient;
use App\Services\RdService\RdServiceNetWalletRefundReversalClient;
use App\Services\Refunds\WalletRefundReversalResolver;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WalletRefundReversalResolverTest extends TestCase
{
    public function test_rn_order_resolves_to_rdservice_net_client(): void
    {
        $client = app(WalletRefundReversalResolver::class)->forOrderId('RN158');

        $this->assertInstanceOf(RdServiceNetWalletRefundReversalClient::class, $client);
    }

    public function test_ra_order_resolves_to_rdservice_net_client(): void
    {
        $client = app(WalletRefundReversalResolver::class)->forOrderId('RA3506965');

        $this->assertInstanceOf(RdServiceNetWalletRefundReversalClient::class, $client);
    }

    public function test_rnp_order_resolves_to_rdservice_net_client(): void
    {
        $client = app(WalletRefundReversalResolver::class)->forOrderId('RNP42');

        $this->assertInstanceOf(RdServiceNetWalletRefundReversalClient::class, $client);
    }

    public function test_rdservice_in_order_resolves_to_rdservice_in_client(): void
    {
        $client = app(WalletRefundReversalResolver::class)->forOrderId('RD3147');

        $this->assertInstanceOf(RdServiceInWalletRefundReversalClient::class, $client);
    }

    public function test_radiumbox_order_resolves_to_radiumbox_client(): void
    {
        $client = app(WalletRefundReversalResolver::class)->forOrderId('RB484');

        $this->assertInstanceOf(RadiumBoxWalletRefundReversalClient::class, $client);
    }

    public function test_unknown_owner_fails_safely(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('No wallet reversal destination exists for this order source.');

        app(WalletRefundReversalResolver::class)->forOrderId('UNKNOWN123');
    }
}
