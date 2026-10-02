<?php

namespace Tests\Feature\CentralWallet;

use App\CentralWallet\Domain\Enums\AccountLinkStatus;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletAccountLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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

    public function test_rdin_origin_refund_visible_on_radiumbox(): void
    {
        $this->walletVisibility(self::BOX, '507', 'unique@example.com')
            ->assertOk()
            ->assertJsonPath('wallet_balance', '499.00')
            ->assertJsonPath('balance_status', 'unverified');
    }

    public function test_no_match_returns_no_disclosure(): void
    {
        $this->walletVisibility(self::RDIN, '506', 'nobody@example.com', '9000000000')
            ->assertNotFound()
            ->assertJsonPath('wallet_balance', '0.00');
    }

    public function test_trusted_verified_email_link_with_zero_ledger_still_returns_historical_unverified(): void
    {
        $cwid = (string) Str::uuid();
        $customerId = (string) Str::uuid();

        \DB::table('central_wallets')->insert([
            'id' => $cwid,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \DB::table('central_customers')->insert([
            'id' => $customerId,
            'central_wallet_id' => $cwid,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        CentralWalletAccountLink::query()->create([
            'central_wallet_id' => $cwid,
            'desk_customer_id' => $customerId,
            'site_code' => self::RDIN,
            'local_user_id' => '3',
            'status' => AccountLinkStatus::Active,
            'verification_method' => 'verified_email',
            'created_by' => 'test',
            'linked_at' => now(),
        ]);

        $this->walletVisibility(self::RDIN, '3', 'unique@example.com', null, true)
            ->assertOk()
            ->assertJsonPath('wallet_balance', '499.00')
            ->assertJsonPath('balance_status', 'unverified')
            ->assertJsonPath('balance_source', 'historical_wallet_refund')
            ->assertJsonPath('spendable', false)
            ->assertJsonPath('verification_required', true)
            ->assertJsonMissingPath('central_wallet_id')
            ->assertJsonMissingPath('desk_customer_id');
    }

    private function walletVisibility(
        string $site,
        string $localUserId,
        string $email,
        ?string $mobile = null,
        bool $emailVerified = false,
    ): TestResponse {
        $query = [
            'site_code' => $site,
            'local_user_id' => $localUserId,
            'email' => $email,
            'email_verified' => $emailVerified,
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
