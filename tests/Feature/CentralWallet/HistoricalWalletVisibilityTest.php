<?php

namespace Tests\Feature\CentralWallet;

use App\CentralWallet\Domain\Enums\AccountLinkStatus;
use App\CentralWallet\Domain\Enums\CustomerIdentityCredentialType;
use App\CentralWallet\Domain\Enums\RefundMigrationLane;
use App\CentralWallet\Domain\Enums\RefundMigrationStatus;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomerIdentityCredential;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletAccountLink;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletRefundMigration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class HistoricalWalletVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'cw-historical-visibility-token';

    private const RDIN = 'rdservice.in';

    private const BOX = 'radiumbox.com';

    private const E1_FIXTURE = __DIR__.'/../../fixtures/cw-e1-verification-cohort-test-fixture.json';

    private const E2_FIXTURE = __DIR__.'/../../fixtures/cw-e2-verification-cohort-test-fixture.json';

    private const HIST_FIXTURE = __DIR__.'/../../fixtures/cw-identity-required-cohort-test-fixture.json';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'central_wallet.enabled' => true,
            'central_wallet.api_enabled' => true,
            'central_wallet.integration_token' => self::TOKEN,
            'central_wallet.customer_identity.enabled' => true,
            'central_wallet.customer_identity.verified_email_enabled' => true,
            'central_wallet.provisional_identity.enabled' => true,
            'central_wallet.provisional_identity.financial_gate_enabled' => true,
            'central_wallet.reservations.enabled' => true,
            'central_wallet.historical_wallet_visibility.enabled' => true,
            'central_wallet.identity_required_cohort.provisional_display_enabled' => true,
            'central_wallet.identity_required_cohort.campaign_manifest_path' => self::HIST_FIXTURE,
            'central_wallet.identity_required_cohort.expected_refunds' => 7,
            'central_wallet.identity_required_cohort.expected_amount' => '2150.00',
            'central_wallet.e1_identity_migration.verification_enabled' => true,
            'central_wallet.e1_identity_migration.verification_cohort_manifest_path' => self::E1_FIXTURE,
            'central_wallet.e1_identity_migration.expected_count' => 3,
            'central_wallet.e1_identity_migration.expected_amount' => '1500.00',
            'central_wallet.e2_historical_settlement.verification_enabled' => true,
            'central_wallet.e2_historical_settlement.verification_cohort_manifest_path' => self::E2_FIXTURE,
            'central_wallet.e2_historical_settlement.expected_count' => 3,
            'central_wallet.e2_historical_settlement.expected_amount' => '1714.00',
            'central_wallet.refund_migration.execution_enabled' => false,
            'central_wallet.e2_historical_settlement.execution_enabled' => false,
        ]);
    }

    public function test_e1_unverified_customer_sees_historical_amount(): void
    {
        $this->walletVisibility(self::RDIN, '400001', 'e1user@example.com')
            ->assertOk()
            ->assertJsonPath('balance_status', 'unverified')
            ->assertJsonPath('wallet_balance', '1000.00')
            ->assertJsonPath('balance_source', 'historical_wallet_refund')
            ->assertJsonPath('spendable', false)
            ->assertJsonPath('verification_required', true)
            ->assertJsonPath('display_label', 'Wallet Balance — Unverified');
    }

    public function test_e2_unverified_customer_sees_historical_amount(): void
    {
        $this->walletVisibility(self::RDIN, '200001', 'e2user@example.com')
            ->assertOk()
            ->assertJsonPath('balance_status', 'unverified')
            ->assertJsonPath('wallet_balance', '1214.00')
            ->assertJsonPath('spendable', false);
    }

    public function test_verified_customer_sees_verified_balance(): void
    {
        [$customerId, $cwid] = $this->seedCustomerWithVerifiedEmail('verified@example.com', '75.00');

        CentralWalletAccountLink::query()->create([
            'central_wallet_id' => $cwid,
            'desk_customer_id' => $customerId,
            'site_code' => self::BOX,
            'local_user_id' => '55',
            'status' => AccountLinkStatus::Active,
            'verification_method' => 'verified_email',
            'created_by' => 'test',
            'linked_at' => now(),
        ]);

        $this->walletVisibility(self::BOX, '55', 'verified@example.com')
            ->assertOk()
            ->assertJsonPath('balance_status', 'verified')
            ->assertJsonPath('wallet_balance', '75.00')
            ->assertJsonPath('balance_source', 'central_wallet')
            ->assertJsonPath('spendable', true)
            ->assertJsonPath('verification_required', false);
    }

    public function test_unverified_customer_cannot_redeem_via_reservation(): void
    {
        $cwid = (string) Str::uuid();
        $this->seedWallet($cwid, '100.00');

        $this->withHeaders($this->authHeaders(self::RDIN))
            ->postJson('/api/central-wallet/v1/wallet-reservations', [
                'idempotency_key' => 'reserve-unverified',
                'central_wallet_id' => $cwid,
                'amount' => '10.00',
                'business_reference' => 'order-unverified',
                'local_user_id' => '400001',
            ])
            ->assertForbidden()
            ->assertJsonPath('error', 'trusted_account_link_required');
    }

    public function test_verified_customer_can_create_reservation(): void
    {
        [$customerId, $cwid] = $this->seedCustomerWithVerifiedEmail('spend@example.com', '200.00');

        CentralWalletAccountLink::query()->create([
            'central_wallet_id' => $cwid,
            'desk_customer_id' => $customerId,
            'site_code' => self::BOX,
            'local_user_id' => '88',
            'status' => AccountLinkStatus::Active,
            'verification_method' => 'verified_email',
            'created_by' => 'test',
            'linked_at' => now(),
        ]);

        $this->withHeaders($this->authHeaders(self::BOX))
            ->postJson('/api/central-wallet/v1/wallet-reservations', [
                'idempotency_key' => 'reserve-verified',
                'central_wallet_id' => $cwid,
                'amount' => '10.00',
                'business_reference' => 'order-verified',
                'local_user_id' => '88',
            ])
            ->assertCreated();
    }

    public function test_ambiguous_customer_does_not_receive_balance(): void
    {
        $this->seedCustomerWithVerifiedEmail('ambig@example.com', '50.00');
        $otherCwid = (string) Str::uuid();
        $this->seedWallet($otherCwid);

        CentralWalletAccountLink::query()->create([
            'central_wallet_id' => $otherCwid,
            'site_code' => self::BOX,
            'local_user_id' => '99',
            'status' => AccountLinkStatus::Active,
            'verification_method' => 'm2_dual_otp',
            'created_by' => 'test',
            'linked_at' => now(),
        ]);

        $this->walletVisibility(self::BOX, '99', 'ambig@example.com')
            ->assertStatus(409)
            ->assertJsonPath('balance_status', 'verification_required')
            ->assertJsonPath('wallet_balance', '0.00')
            ->assertJsonPath('spendable', false);
    }

    public function test_no_customer_refund_is_not_exposed(): void
    {
        $this->walletVisibility(self::RDIN, '999999', 'nobody@example.com')
            ->assertNotFound()
            ->assertJsonPath('wallet_balance', '0.00')
            ->assertJsonPath('balance_status', 'verification_required');
    }

    public function test_multiple_e1_refunds_aggregate_once(): void
    {
        $this->walletVisibility(self::RDIN, '400001', 'e1user@example.com')
            ->assertOk()
            ->assertJsonPath('wallet_balance', '1000.00');
    }

    public function test_reconciled_refund_is_not_double_counted(): void
    {
        CentralWalletRefundMigration::query()->create([
            'id' => (string) Str::uuid(),
            'batch_id' => 'test-batch',
            'refund_id' => 92001,
            'refund_reference' => 'REF-TEST-92001',
            'amount' => '500.00',
            'source_type' => 'spoke_wallet',
            'source_application' => self::RDIN,
            'source_wallet_id' => 99001,
            'source_reference' => 'desk-refund-migration:refund_requests:92001',
            'lane' => RefundMigrationLane::Lane1SpokeCutover,
            'status' => RefundMigrationStatus::Reconciled,
            'idempotency_key' => 'test:92001',
            'prepared_at' => now(),
            'metadata' => [],
        ]);

        $this->walletVisibility(self::RDIN, '400001', 'e1user@example.com')
            ->assertOk()
            ->assertJsonPath('wallet_balance', '500.00');
    }

    public function test_refund_300_is_visible_when_in_contact_index_and_not_protected(): void
    {
        config([
            'central_wallet.historical_wallet_visibility.protected_refund_ids' => [],
            'central_wallet.historical_wallet_visibility.contact_match_enabled' => true,
            'central_wallet.historical_wallet_visibility.contact_index_manifest_path' => __DIR__.'/../../fixtures/cw-refund-300-contact-index-fixture.json',
            'central_wallet.e1_identity_migration.verification_enabled' => false,
            'central_wallet.e2_historical_settlement.verification_enabled' => false,
            'central_wallet.identity_required_cohort.provisional_display_enabled' => false,
        ]);

        $this->walletVisibility(self::BOX, '3', 'user3@example.com', '9852525656')
            ->assertOk()
            ->assertJsonPath('wallet_balance', '499.00')
            ->assertJsonPath('balance_status', 'unverified')
            ->assertJsonPath('spendable', false);

        $this->walletVisibility(self::RDIN, '3', 'user3@example.com', '9852525656')
            ->assertOk()
            ->assertJsonPath('wallet_balance', '499.00')
            ->assertJsonPath('spendable', false);
    }

    public function test_refund_300_remains_hidden_when_explicitly_protected(): void
    {
        config([
            'central_wallet.historical_wallet_visibility.protected_refund_ids' => [300],
            'central_wallet.historical_wallet_visibility.contact_match_enabled' => true,
            'central_wallet.historical_wallet_visibility.contact_index_manifest_path' => __DIR__.'/../../fixtures/cw-refund-300-contact-index-fixture.json',
            'central_wallet.e1_identity_migration.verification_enabled' => false,
            'central_wallet.e2_historical_settlement.verification_enabled' => false,
            'central_wallet.identity_required_cohort.provisional_display_enabled' => false,
        ]);

        $this->walletVisibility(self::RDIN, '3', 'user3@example.com')
            ->assertNotFound()
            ->assertJsonPath('wallet_balance', '0.00');
    }

    public function test_provisional_resolve_includes_unified_visibility_fields(): void
    {
        $this->withHeaders($this->authHeaders(self::RDIN))
            ->postJson('/api/central-wallet/v1/customer-identity/provisional-resolve', [
                'idempotency_key' => 'idem-unified',
                'site_code' => self::RDIN,
                'local_user_id' => '400001',
                'email' => 'e1user@example.com',
                'email_verified' => false,
            ])
            ->assertOk()
            ->assertJsonPath('wallet_balance', '1000.00')
            ->assertJsonPath('balance_status', 'unverified')
            ->assertJsonPath('spendable', false);
    }

    public function test_wallet_visibility_endpoint_requires_visibility_flag(): void
    {
        config(['central_wallet.historical_wallet_visibility.enabled' => false]);

        $this->withHeaders($this->authHeaders(self::RDIN))
            ->getJson('/api/central-wallet/v1/wallet-visibility?'.http_build_query([
                'site_code' => self::RDIN,
                'local_user_id' => '400001',
                'email' => 'e1user@example.com',
            ]))
            ->assertStatus(503)
            ->assertJsonPath('error', 'historical_wallet_visibility_disabled');
    }

    private function walletVisibility(string $site, string $localUserId, string $email, ?string $mobile = null): TestResponse
    {
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
     * @return array{0: string, 1: string}
     */
    private function seedCustomerWithVerifiedEmail(string $email, string $balance): array
    {
        $cwid = (string) Str::uuid();
        $customerId = (string) Str::uuid();
        $this->seedWallet($cwid, $balance);

        CentralCustomer::query()->create([
            'id' => $customerId,
            'central_wallet_id' => $cwid,
            'status' => 'active',
        ]);

        CentralCustomerIdentityCredential::query()->create([
            'desk_customer_id' => $customerId,
            'credential_type' => CustomerIdentityCredentialType::VerifiedEmail,
            'provider' => 'desk_email',
            'subject_hash' => hash('sha256', 'verified_email:'.strtolower(trim($email))),
            'verified_at' => now(),
        ]);

        return [$customerId, $cwid];
    }

    private function seedWallet(string $cwid, string $balance = '0.00'): void
    {
        \DB::table('central_wallets')->insert([
            'id' => $cwid,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (bccomp($balance, '0', 2) > 0) {
            \DB::table('central_wallet_ledger_entries')->insert([
                'central_wallet_id' => $cwid,
                'entry_type' => 'credit',
                'amount' => $balance,
                'currency' => 'INR',
                'status' => 'posted',
                'source_system' => 'test',
                'correlation_id' => (string) Str::uuid(),
                'posted_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
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
