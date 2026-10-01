<?php

namespace Tests\Feature\CentralWallet;

use App\CentralWallet\Application\CeremonyVerificationProofValidator;
use App\CentralWallet\Domain\Enums\AccountLinkStatus;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletAccountLink;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletRefundMigration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class IdentityRequiredCohortProvisionalIdentityTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'cw-identity-required-cohort-token';

    private const SITE = 'rdservice.in';

    private const CEREMONY_SECRET = 'test-rdin-ceremony-signing-secret-32';

    private const FIXTURE = __DIR__.'/../../fixtures/cw-identity-required-cohort-test-fixture.json';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'central_wallet.enabled' => true,
            'central_wallet.api_enabled' => true,
            'central_wallet.integration_token' => self::TOKEN,
            'central_wallet.customer_identity.enabled' => true,
            'central_wallet.customer_identity.google_enabled' => true,
            'central_wallet.customer_identity.verified_email_enabled' => true,
            'central_wallet.provisional_identity.enabled' => true,
            'central_wallet.provisional_identity.financial_gate_enabled' => true,
            'central_wallet.reservations.enabled' => true,
            'central_wallet.refund_migration.execution_enabled' => false,
            'central_wallet.identity_required_cohort.provisional_display_enabled' => true,
            'central_wallet.identity_required_cohort.campaign_manifest_path' => self::FIXTURE,
            'central_wallet.identity_required_cohort.expected_refunds' => 7,
            'central_wallet.identity_required_cohort.expected_amount' => '2150.00',
            'central_wallet.ceremony.signing_secrets' => [
                self::SITE => self::CEREMONY_SECRET,
            ],
        ]);
    }

    public function test_unverified_email_in_cohort_returns_read_only_local_wallet_balance(): void
    {
        $this->provisionalResolve('100001', 'user@example.com', null, false, 'idem-hist-1')
            ->assertOk()
            ->assertJsonPath('identity_state', 'provisional')
            ->assertJsonPath('verification_status', 'unverified')
            ->assertJsonPath('available_balance', '499.00')
            ->assertJsonPath('balance_source', 'local_spoke_wallet')
            ->assertJsonPath('verification_required', true)
            ->assertJsonPath('financial_use_requires_verification', true)
            ->assertJsonPath('historical_migration_separate', true)
            ->assertJsonMissing(['central_wallet_id', 'desk_customer_id']);
    }

    public function test_unverified_mobile_only_in_cohort_returns_read_only_balance(): void
    {
        $this->provisionalResolve('100002', null, '919876543210', false, 'idem-hist-2')
            ->assertOk()
            ->assertJsonPath('identity_state', 'provisional')
            ->assertJsonPath('available_balance', '597.00')
            ->assertJsonPath('balance_source', 'local_spoke_wallet');
    }

    public function test_email_and_mobile_unverified_returns_read_only_balance(): void
    {
        $this->provisionalResolve('100001', 'user@example.com', '919876543210', false, 'idem-hist-3')
            ->assertOk()
            ->assertJsonPath('identity_state', 'provisional')
            ->assertJsonPath('available_balance', '499.00');
    }

    public function test_no_contact_data_is_rejected(): void
    {
        $this->withHeaders($this->authHeaders())
            ->postJson('/api/central-wallet/v1/customer-identity/provisional-resolve', [
                'idempotency_key' => 'idem-no-contact',
                'site_code' => self::SITE,
                'local_user_id' => '100001',
                'email_verified' => false,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'contact_data_required');
    }

    public function test_user_not_in_cohort_is_unresolved(): void
    {
        $this->provisionalResolve('999999', 'user@example.com', null, false, 'idem-not-cohort')
            ->assertNotFound()
            ->assertJsonPath('identity_state', 'unresolved');
    }

    public function test_source_wallet_unresolved_blocks_provisional_display(): void
    {
        $this->provisionalResolve('100003', 'user@example.com', null, false, 'idem-source-block')
            ->assertNotFound()
            ->assertJsonPath('error', 'source_reconciliation_required')
            ->assertJsonPath('blocker', 'authoritative_source_wallet_unresolved');
    }

    public function test_multiple_refunds_aggregate_spendable_balance_for_same_user(): void
    {
        $this->provisionalResolve('100005', 'user@example.com', null, false, 'idem-aggregate')
            ->assertOk()
            ->assertJsonPath('available_balance', '250.00');
    }

    public function test_unverified_balance_cannot_authorize_financial_mutation(): void
    {
        $cwid = (string) Str::uuid();
        $this->seedWallet($cwid);

        $this->withHeaders($this->authHeaders())
            ->postJson('/api/central-wallet/v1/wallet-reservations', [
                'idempotency_key' => 'reserve-unverified-cohort',
                'central_wallet_id' => $cwid,
                'amount' => '10.00',
                'business_reference' => 'order-cohort-1',
                'local_user_id' => '100001',
            ])
            ->assertForbidden()
            ->assertJsonPath('error', 'trusted_account_link_required');
    }

    public function test_successful_google_identity_resolution_creates_customer_and_link(): void
    {
        $this->resolveGoogle('100001', 'google-subject-cohort', 'idem-google-cohort')
            ->assertCreated()
            ->assertJsonStructure(['desk_customer_id', 'central_wallet_id', 'link_id']);

        $this->assertDatabaseHas('central_wallet_account_links', [
            'site_code' => self::SITE,
            'local_user_id' => '100001',
            'status' => AccountLinkStatus::Active->value,
            'verification_method' => 'trusted_google',
        ]);
    }

    public function test_successful_verified_email_identity_resolution_creates_customer_and_link(): void
    {
        $this->resolveVerifiedEmail('100002', 'verified@example.com', 'idem-email-cohort')
            ->assertCreated();

        $this->assertDatabaseHas('central_wallet_account_links', [
            'site_code' => self::SITE,
            'local_user_id' => '100002',
            'verification_method' => 'verified_email',
        ]);
    }

    public function test_successful_mobile_otp_ceremony_creates_trusted_link(): void
    {
        $before = CentralWalletRefundMigration::query()->count();
        $proof = CeremonyVerificationProofValidator::issueForTesting(
            siteCode: self::SITE,
            localUserId: '100001',
            ceremonyAttemptId: (string) Str::uuid(),
            verifiedPhoneE164Hash: hash('sha256', '+919876543210'),
            secret: self::CEREMONY_SECRET,
        );

        $this->withHeaders($this->authHeaders())
            ->postJson('/api/central-wallet/v1/ceremony/complete', [
                'idempotency_key' => 'idem-m2-cohort',
                'site_code' => self::SITE,
                'local_user_id' => '100001',
                'ceremony_verification_ref' => $proof,
            ])
            ->assertCreated()
            ->assertJsonPath('status', 'connected');

        $this->assertDatabaseHas('central_wallet_account_links', [
            'site_code' => self::SITE,
            'local_user_id' => '100001',
            'status' => AccountLinkStatus::Active->value,
            'verification_method' => 'm2_whatsapp_otp',
        ]);
        $this->assertSame($before, CentralWalletRefundMigration::query()->count());
    }

    public function test_verified_identity_resolution_does_not_trigger_refund_migration(): void
    {
        $before = CentralWalletRefundMigration::query()->count();

        $this->resolveGoogle('100001', 'google-subject-no-migrate', 'idem-no-migrate')
            ->assertCreated();

        $this->assertSame($before, CentralWalletRefundMigration::query()->count());
        $this->assertDatabaseCount('central_wallet_ledger_entries', 0);
    }

    public function test_ambiguous_non_trusted_active_link_fails_closed(): void
    {
        $cwid = (string) Str::uuid();
        $this->seedWallet($cwid);

        CentralWalletAccountLink::query()->create([
            'central_wallet_id' => $cwid,
            'site_code' => self::SITE,
            'local_user_id' => '100001',
            'status' => AccountLinkStatus::Active,
            'verification_method' => 'owner_migration_cohort',
            'created_by' => 'test',
            'linked_at' => now(),
        ]);

        $this->provisionalResolve('100001', 'user@example.com', null, false, 'idem-ambiguous-link')
            ->assertStatus(409)
            ->assertJsonPath('identity_state', 'unresolved');
    }

    public function test_trusted_active_link_requires_trusted_identity_path(): void
    {
        $cwid = (string) Str::uuid();
        $customerId = (string) Str::uuid();
        $this->seedWallet($cwid);

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
            'site_code' => self::SITE,
            'local_user_id' => '100001',
            'status' => AccountLinkStatus::Active,
            'verification_method' => 'verified_email',
            'created_by' => 'test',
            'linked_at' => now(),
        ]);

        $this->provisionalResolve('100001', 'user@example.com', null, false, 'idem-trusted-link')
            ->assertStatus(422)
            ->assertJsonPath('error', 'use_trusted_identity_path');
    }

    public function test_cohort_flag_off_preserves_existing_unresolved_behavior(): void
    {
        config(['central_wallet.identity_required_cohort.provisional_display_enabled' => false]);

        $this->provisionalResolve('100001', 'user@example.com', null, false, 'idem-flag-off')
            ->assertNotFound()
            ->assertJsonPath('identity_state', 'unresolved');
    }

    private function provisionalResolve(
        string $localUserId,
        ?string $email,
        ?string $mobile,
        bool $emailVerified,
        string $idempotencyKey,
    ): TestResponse {
        $payload = [
            'idempotency_key' => $idempotencyKey,
            'site_code' => self::SITE,
            'local_user_id' => $localUserId,
            'email_verified' => $emailVerified,
        ];

        if ($email !== null) {
            $payload['email'] = $email;
        }
        if ($mobile !== null) {
            $payload['mobile'] = $mobile;
        }

        return $this->withHeaders($this->authHeaders())
            ->postJson('/api/central-wallet/v1/customer-identity/provisional-resolve', $payload);
    }

    private function resolveGoogle(string $localUserId, string $googleSubject, string $idempotencyKey): TestResponse
    {
        return $this->withHeaders($this->authHeaders())
            ->postJson('/api/central-wallet/v1/customer-identity/resolve', [
                'idempotency_key' => $idempotencyKey,
                'site_code' => self::SITE,
                'local_user_id' => $localUserId,
                'identity' => [
                    'type' => 'google',
                    'google_subject' => $googleSubject,
                ],
            ]);
    }

    private function resolveVerifiedEmail(string $localUserId, string $email, string $idempotencyKey): TestResponse
    {
        return $this->withHeaders($this->authHeaders())
            ->postJson('/api/central-wallet/v1/customer-identity/resolve', [
                'idempotency_key' => $idempotencyKey,
                'site_code' => self::SITE,
                'local_user_id' => $localUserId,
                'identity' => [
                    'type' => 'verified_email',
                    'email' => $email,
                ],
            ]);
    }

    /**
     * @return array<string, string>
     */
    private function authHeaders(): array
    {
        return [
            'Authorization' => 'Bearer '.self::TOKEN,
            'X-Site-Code' => self::SITE,
        ];
    }

    private function seedWallet(string $cwid): void
    {
        \DB::table('central_wallets')->insert([
            'id' => $cwid,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
