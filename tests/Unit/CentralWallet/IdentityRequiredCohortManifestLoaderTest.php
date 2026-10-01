<?php

namespace Tests\Unit\CentralWallet;

use App\CentralWallet\Application\IdentityRequiredCohortManifestLoader;
use InvalidArgumentException;
use Tests\TestCase;

class IdentityRequiredCohortManifestLoaderTest extends TestCase
{
    public function test_loads_test_fixture_and_aggregates_site_user_balances(): void
    {
        config([
            'central_wallet.identity_required_cohort.campaign_manifest_path' => base_path('tests/fixtures/cw-identity-required-cohort-test-fixture.json'),
            'central_wallet.identity_required_cohort.expected_refunds' => 7,
            'central_wallet.identity_required_cohort.expected_amount' => '2150.00',
        ]);

        $loader = new IdentityRequiredCohortManifestLoader;
        $manifest = $loader->load();

        $this->assertSame(7, $manifest['refund_count']);
        $this->assertSame('2150.00', $manifest['amount']);

        $member = $loader->findSiteUser($manifest, 'rdservice.in', '100005');
        $this->assertNotNull($member);
        $this->assertSame('250.00', $member['spendable_balance']);
        $this->assertCount(2, $member['refund_ids']);
    }

    public function test_rejects_invalid_refund_count(): void
    {
        config([
            'central_wallet.identity_required_cohort.campaign_manifest_path' => base_path('tests/fixtures/cw-identity-required-cohort-test-fixture.json'),
            'central_wallet.identity_required_cohort.expected_refunds' => 220,
            'central_wallet.identity_required_cohort.expected_amount' => '127328.00',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('identity_required_cohort_refund_count_mismatch');

        (new IdentityRequiredCohortManifestLoader)->load();
    }
}
