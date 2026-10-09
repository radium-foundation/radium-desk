<?php

namespace Tests\Unit\CentralWallet;

use App\CentralWallet\Reliability\CentralWalletAccountLinkReconciliationAnalyzer;
use PHPUnit\Framework\TestCase;

final class AccountLinkReconciliationAnalyzerTest extends TestCase
{
    private CentralWalletAccountLinkReconciliationAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->analyzer = new CentralWalletAccountLinkReconciliationAnalyzer();
    }

    public function test_match_when_site_user_and_cwid_align(): void
    {
        $result = $this->analyzer->reconcile(
            'rdservice.in',
            [
                ['id' => 1, 'local_user_id' => '3', 'central_wallet_id' => 'cw-1', 'status' => 'active', 'verification_method' => 'verified_email'],
            ],
            [
                ['id' => 1, 'local_user_id' => 3, 'central_wallet_id' => 'cw-1', 'link_status' => 'active', 'verification_method' => 'verified_email', 'desk_link_id' => 1],
            ],
        );

        $this->assertSame(1, $result['counts']['match']);
        $this->assertSame(0, $result['counts']['desk_missing_on_spoke']);
    }

    public function test_desk_missing_on_spoke_classification(): void
    {
        $result = $this->analyzer->reconcile(
            'rdservice.in',
            [
                ['id' => 77, 'local_user_id' => '558781', 'central_wallet_id' => 'cw-2', 'status' => 'active', 'verification_method' => 'owner_migration_cohort'],
            ],
            [],
        );

        $this->assertSame(0, $result['counts']['match']);
        $this->assertSame(1, $result['counts']['desk_missing_on_spoke']);
    }

    public function test_wallet_id_mismatch_classification(): void
    {
        $result = $this->analyzer->reconcile(
            'radiumbox.com',
            [
                ['id' => 7, 'local_user_id' => '3', 'central_wallet_id' => 'cw-a', 'status' => 'active', 'verification_method' => 'm2_dual_otp'],
            ],
            [
                ['id' => 1, 'local_user_id' => '3', 'central_wallet_id' => 'cw-b', 'link_status' => 'active', 'verification_method' => 'm2_dual_otp', 'desk_link_id' => 7],
            ],
        );

        $this->assertSame(1, $result['counts']['wallet_id_mismatch']);
        $this->assertSame(0, $result['counts']['match']);
    }
}
