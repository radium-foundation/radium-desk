<?php

namespace Tests\Unit\Refunds;

use App\Support\Refunds\RadiumBoxLegacyWalletRefundExecutionPolicy;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RadiumBoxLegacyWalletRefundExecutionPolicyTest extends TestCase
{
    public function test_legacy_spendable_credit_allowed_by_default(): void
    {
        config(['central_wallet.wallet_refund.block_radiumbox_legacy_spendable_without_desk_ledger' => false]);

        RadiumBoxLegacyWalletRefundExecutionPolicy::assertLegacySpendableCreditAllowed();

        $this->assertFalse(RadiumBoxLegacyWalletRefundExecutionPolicy::blocksLegacySpendableCredit());
    }

    public function test_blocks_legacy_spendable_credit_when_config_enabled(): void
    {
        config(['central_wallet.wallet_refund.block_radiumbox_legacy_spendable_without_desk_ledger' => true]);

        $this->expectException(ValidationException::class);
        RadiumBoxLegacyWalletRefundExecutionPolicy::assertLegacySpendableCreditAllowed();
    }
}
