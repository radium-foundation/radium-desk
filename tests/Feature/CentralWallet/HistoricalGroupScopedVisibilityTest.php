<?php

namespace Tests\Feature\CentralWallet;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class HistoricalGroupScopedVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'cw-group-scoped-visibility-token';

    private const RDIN = 'rdservice.in';

    private const BOX = 'radiumbox.com';

    private const CONTACT_FIXTURE = __DIR__.'/../../fixtures/cw-historical-contact-index-test-fixture.json';

    private const E1_FIXTURE = __DIR__.'/../../fixtures/cw-e1-verification-cohort-test-fixture.json';

    private const E2_FIXTURE = __DIR__.'/../../fixtures/cw-e2-verification-cohort-test-fixture.json';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'central_wallet.enabled' => true,
            'central_wallet.api_enabled' => true,
            'central_wallet.integration_token' => self::TOKEN,
            'central_wallet.historical_wallet_visibility.enabled' => true,
            'central_wallet.historical_wallet_visibility.contact_match_enabled' => true,
            'central_wallet.historical_wallet_visibility.contact_index_manifest_path' => self::CONTACT_FIXTURE,
            'central_wallet.e1_identity_migration.verification_enabled' => true,
            'central_wallet.e1_identity_migration.verification_cohort_manifest_path' => self::E1_FIXTURE,
            'central_wallet.e1_identity_migration.expected_count' => 3,
            'central_wallet.e1_identity_migration.expected_amount' => '1500.00',
            'central_wallet.e2_historical_settlement.verification_enabled' => true,
            'central_wallet.e2_historical_settlement.verification_cohort_manifest_path' => self::E2_FIXTURE,
            'central_wallet.e2_historical_settlement.expected_count' => 3,
            'central_wallet.e2_historical_settlement.expected_amount' => '1714.00',
            'central_wallet.identity_required_cohort.provisional_display_enabled' => false,
            'central_wallet.provisional_identity.financial_gate_enabled' => true,
            'central_wallet.reservations.enabled' => true,
        ]);
    }

    public function test_radiumbox_sees_rdin_origin_contact_match_refund(): void
    {
        $this->walletVisibility(self::BOX, 'box-501', 'unique@example.com')
            ->assertOk()
            ->assertJsonPath('wallet_balance', '499.00')
            ->assertJsonPath('balance_status', 'unverified')
            ->assertJsonPath('spendable', false);
    }

    public function test_rdin_sees_radiumbox_origin_contact_match_refund(): void
    {
        $this->walletVisibility(self::RDIN, 'rdin-503', 'ambig@example.com', '9876543210')
            ->assertOk()
            ->assertJsonPath('wallet_balance', '300.00');
    }

    public function test_combined_balance_from_multiple_origin_sites(): void
    {
        $this->walletVisibility(self::BOX, 'box-cross', 'cross@example.com', '9888877776')
            ->assertOk()
            ->assertJsonPath('wallet_balance', '750.00');
    }

    public function test_rdservice_net_origin_contributes_to_group_balance(): void
    {
        $this->walletVisibility(self::BOX, 'box-net', 'netuser@example.com')
            ->assertOk()
            ->assertJsonPath('wallet_balance', '150.00');
    }

    public function test_e1_rdin_origin_visible_on_radiumbox_via_contact(): void
    {
        $this->walletVisibility(self::BOX, 'box-e1', 'e1user@example.com')
            ->assertOk()
            ->assertJsonPath('wallet_balance', '1000.00')
            ->assertJsonPath('balance_status', 'unverified');
    }

    public function test_e2_rdin_origin_visible_on_radiumbox_via_email(): void
    {
        $this->walletVisibility(self::BOX, 'box-e2', 'e2user@example.com')
            ->assertOk()
            ->assertJsonPath('wallet_balance', '1214.00')
            ->assertJsonPath('spendable', false);
    }

    public function test_same_balance_on_both_group_sites_for_shared_customer(): void
    {
        $rdin = $this->walletVisibility(self::RDIN, 'rdin-shared', 'unique@example.com');
        $box = $this->walletVisibility(self::BOX, 'box-shared', 'unique@example.com');

        $rdin->assertOk()->assertJsonPath('wallet_balance', '499.00');
        $box->assertOk()->assertJsonPath('wallet_balance', '499.00');
    }

    public function test_unverified_customer_cannot_redeem_cross_site_balance(): void
    {
        $this->withHeaders($this->authHeaders(self::BOX))
            ->postJson('/api/central-wallet/v1/wallet-reservations', [
                'idempotency_key' => 'reserve-cross-site-unverified',
                'central_wallet_id' => '00000000-0000-4000-8000-000000000099',
                'amount' => '10.00',
                'business_reference' => 'order-cross-site',
                'local_user_id' => 'box-501',
            ])
            ->assertForbidden()
            ->assertJsonPath('error', 'trusted_account_link_required');
    }

    public function test_internal_ids_are_not_exposed(): void
    {
        $this->walletVisibility(self::BOX, 'box-501', 'unique@example.com')
            ->assertOk()
            ->assertJsonMissingPath('refund_id')
            ->assertJsonMissingPath('desk_customer_id')
            ->assertJsonMissingPath('central_wallet_id');
    }

    private function walletVisibility(
        string $site,
        string $localUserId,
        string $email,
        ?string $mobile = null,
    ): TestResponse {
        $query = [
            'site_code' => $site,
            'local_user_id' => $localUserId,
            'email' => $email,
            'email_verified' => false,
        ];

        if ($mobile !== null && $mobile !== '') {
            $query['mobile'] = $mobile;
        }

        return $this->withHeaders($this->authHeaders($site))
            ->getJson('/api/central-wallet/v1/wallet-visibility?'.http_build_query($query));
    }

    /**
     * @return array<string, string>
     */
    private function authHeaders(string $site): array
    {
        return [
            'Authorization' => 'Bearer '.self::TOKEN,
            'X-Site-Code' => $site,
            'Accept' => 'application/json',
        ];
    }
}
