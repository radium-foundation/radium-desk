<?php

namespace Tests\Unit\Refunds;

use App\Services\Refunds\WalletRefundDestinationResolver;
use App\Support\Refunds\DeskFirstWalletRefundSpokePolicy;
use Tests\TestCase;

class DeskFirstWalletRefundSpokePolicyDefaultAllowlistTest extends TestCase
{
    public function test_default_allowlist_excludes_rdservice_net_until_spoke_audit_is_deployed(): void
    {
        $allowlist = (string) config('central_wallet.wallet_refund.desk_first_spoke_allowlist');

        $this->assertStringContainsString('rdservice.in', $allowlist);
        $this->assertStringContainsString('radiumbox.com', $allowlist);
        $this->assertStringNotContainsString('rdservice.net', $allowlist);

        $resolver = app(WalletRefundDestinationResolver::class);

        $this->assertFalse(DeskFirstWalletRefundSpokePolicy::isSpokeAllowedForDeskFirst('RN90001', $resolver));
    }
}
