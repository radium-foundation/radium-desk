<?php

namespace Tests\Feature\CentralWallet;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class HistoricalContactIdentityMatchTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'cw-contact-match-token';

    private const RDIN = 'rdservice.in';

    private const BOX = 'radiumbox.com';

    private const CONTACT_FIXTURE = __DIR__.'/../../fixtures/cw-historical-contact-index-test-fixture.json';

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
            'central_wallet.e1_identity_migration.verification_enabled' => false,
            'central_wallet.e2_historical_settlement.verification_enabled' => false,
            'central_wallet.identity_required_cohort.provisional_display_enabled' => false,
        ]);
    }

    public function test_unique_email_match_returns_unverified_historical_balance(): void
    {
        $this->walletVisibility(self::RDIN, '501', 'unique@example.com')
            ->assertOk()
            ->assertJsonPath('balance_status', 'unverified')
            ->assertJsonPath('wallet_balance', '499.00')
            ->assertJsonPath('balance_source', 'historical_wallet_refund')
            ->assertJsonPath('spendable', false)
            ->assertJsonMissingPath('refund_id')
            ->assertJsonMissingPath('desk_customer_id')
            ->assertJsonMissingPath('central_wallet_id');
    }

    public function test_unique_mobile_match_returns_unverified_historical_balance(): void
    {
        $this->walletVisibility(self::RDIN, '502', '', '9123456789')
            ->assertOk()
            ->assertJsonPath('balance_status', 'unverified')
            ->assertJsonPath('wallet_balance', '250.00')
            ->assertJsonPath('spendable', false);
    }

    public function test_email_and_mobile_same_customer_aggregate_once(): void
    {
        $this->walletVisibility(self::BOX, '503', 'ambig@example.com', '9876543210')
            ->assertOk()
            ->assertJsonPath('wallet_balance', '300.00');
    }

    public function test_ambiguous_email_fails_closed(): void
    {
        $this->walletVisibility(self::RDIN, '504', 'ambig@example.com')
            ->assertStatus(409)
            ->assertJsonPath('wallet_balance', '0.00')
            ->assertJsonPath('balance_status', 'verification_required');
    }

    public function test_email_mobile_conflict_fails_closed(): void
    {
        $this->walletVisibility(self::RDIN, '505', 'unique@example.com', '9123456789')
            ->assertStatus(409)
            ->assertJsonPath('wallet_balance', '0.00');
    }

    public function test_no_match_returns_no_disclosure(): void
    {
        $this->walletVisibility(self::RDIN, '506', 'nobody@example.com', '9000000000')
            ->assertNotFound()
            ->assertJsonPath('wallet_balance', '0.00');
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
    private function authHeaders(string $site = self::RDIN): array
    {
        return [
            'Authorization' => 'Bearer '.self::TOKEN,
            'X-Site-Code' => $site,
            'Accept' => 'application/json',
        ];
    }
}
