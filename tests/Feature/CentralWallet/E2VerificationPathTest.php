<?php

namespace Tests\Feature\CentralWallet;

use App\CentralWallet\Application\CeremonyVerificationProofValidator;
use App\CentralWallet\Application\CustomerFoundationFromCeremonyService;
use App\CentralWallet\Application\E2CohortStateResolver;
use App\CentralWallet\Application\E2HistoricalSettlementJournalImportService;
use App\CentralWallet\Domain\Enums\AccountLinkStatus;
use App\CentralWallet\Domain\Enums\E2SettlementDestinationState;
use App\CentralWallet\Domain\Enums\RefundMigrationStatus;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomerIdentityCredential;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletAccountLink;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletCeremonyIdentity;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletRefundMigration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class E2VerificationPathTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'cw-e2-verification-token';

    private const RDIN_SITE = 'rdservice.in';

    private const BOX_SITE = 'radiumbox.com';

    private const CEREMONY_SECRET = 'test-e2-ceremony-signing-secret-32';

    private const COHORT_FIXTURE = __DIR__.'/../../fixtures/cw-e2-verification-cohort-test-fixture.json';

    private const SETTLEMENT_FIXTURE = __DIR__.'/../../fixtures/cw-e2-verification-settlement-test-fixture.json';

    private const E2_EMAIL = 'e2user@example.com';

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
            'central_wallet.identity_required_cohort.provisional_display_enabled' => true,
            'central_wallet.identity_required_cohort.campaign_manifest_path' => __DIR__.'/../../fixtures/cw-identity-required-cohort-test-fixture.json',
            'central_wallet.identity_required_cohort.expected_refunds' => 7,
            'central_wallet.identity_required_cohort.expected_amount' => '2150.00',
            'central_wallet.e2_historical_settlement.verification_enabled' => true,
            'central_wallet.e2_historical_settlement.verification_cohort_manifest_path' => self::COHORT_FIXTURE,
            'central_wallet.e2_historical_settlement.manifest_path' => self::SETTLEMENT_FIXTURE,
            'central_wallet.e2_historical_settlement.expected_count' => 3,
            'central_wallet.e2_historical_settlement.expected_amount' => '1714.00',
            'central_wallet.refund_migration.execution_enabled' => false,
            'central_wallet.e2_historical_settlement.execution_enabled' => false,
            'central_wallet.ceremony.signing_secrets' => [
                self::RDIN_SITE => self::CEREMONY_SECRET,
                self::BOX_SITE => self::CEREMONY_SECRET,
            ],
        ]);
    }

    public function test_e2_customer_with_historical_email_can_enter_verification_flow(): void
    {
        $this->provisionalResolve(self::RDIN_SITE, '200001', self::E2_EMAIL, 'idem-e2-enter')
            ->assertOk()
            ->assertJsonPath('identity_state', 'provisional')
            ->assertJsonPath('verification_status', 'unverified')
            ->assertJsonPath('available_balance', '1214.00')
            ->assertJsonPath('balance_source', 'historical_wallet_refund')
            ->assertJsonPath('verification_required', true)
            ->assertJsonPath('source_wallet_provenance', 'unavailable_not_reconstructed')
            ->assertJsonMissing(['central_wallet_id', 'desk_customer_id', 'refund_id']);
    }

    public function test_unverified_email_cannot_establish_trusted_identity(): void
    {
        $this->withHeaders($this->authHeaders(self::RDIN_SITE))
            ->postJson('/api/central-wallet/v1/customer-identity/resolve', [
                'idempotency_key' => 'idem-unverified-email',
                'site_code' => self::RDIN_SITE,
                'local_user_id' => '200001',
                'identity' => [
                    'type' => 'email',
                    'email' => self::E2_EMAIL,
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'unsupported_identity_type');
    }

    public function test_successful_email_otp_establishes_trusted_identity_and_destination(): void
    {
        $this->resolveVerifiedEmail(self::RDIN_SITE, '200001', self::E2_EMAIL, 'idem-e2-email')
            ->assertCreated();

        $migration91001 = CentralWalletRefundMigration::query()->where('refund_id', 91001)->firstOrFail();
        $migration91002 = CentralWalletRefundMigration::query()->where('refund_id', 91002)->firstOrFail();

        $this->assertSame(RefundMigrationStatus::Prepared, $migration91001->status);
        $this->assertSame(RefundMigrationStatus::Prepared, $migration91002->status);
        $this->assertNotNull($migration91001->desk_customer_id);
        $this->assertNotNull($migration91001->cwid);
        $this->assertSame('SETTLEMENT_DESTINATION_READY', $migration91001->metadata['e2_verification']['state']);
    }

    public function test_successful_mobile_otp_establishes_trusted_identity_without_lane4_execution(): void
    {
        $beforeMigrations = CentralWalletRefundMigration::query()->count();
        $proof = CeremonyVerificationProofValidator::issueForTesting(
            siteCode: self::RDIN_SITE,
            localUserId: '200002',
            ceremonyAttemptId: (string) Str::uuid(),
            verifiedPhoneE164Hash: hash('sha256', '+919876543210'),
            secret: self::CEREMONY_SECRET,
        );

        $this->withHeaders($this->authHeaders(self::RDIN_SITE))
            ->postJson('/api/central-wallet/v1/ceremony/complete', [
                'idempotency_key' => 'idem-e2-mobile',
                'site_code' => self::RDIN_SITE,
                'local_user_id' => '200002',
                'ceremony_verification_ref' => $proof,
            ])
            ->assertCreated()
            ->assertJsonPath('status', 'connected');

        $this->assertDatabaseHas('central_wallet_account_links', [
            'site_code' => self::RDIN_SITE,
            'local_user_id' => '200002',
            'status' => AccountLinkStatus::Active->value,
        ]);
        $this->assertSame($beforeMigrations, CentralWalletRefundMigration::query()->count());
        $this->assertSame(0, CentralWalletLedgerEntry::query()->count());
    }

    public function test_successful_google_identity_establishes_trusted_identity_with_matching_email(): void
    {
        $this->withHeaders($this->authHeaders(self::BOX_SITE))
            ->postJson('/api/central-wallet/v1/customer-identity/resolve', [
                'idempotency_key' => 'idem-e2-google',
                'site_code' => self::BOX_SITE,
                'local_user_id' => '300001',
                'identity' => [
                    'type' => 'google',
                    'google_subject' => 'google-e2-subject',
                    'email' => 'other@example.com',
                ],
            ])
            ->assertCreated();

        $migration = CentralWalletRefundMigration::query()->where('refund_id', 91003)->firstOrFail();
        $this->assertSame(RefundMigrationStatus::Prepared, $migration->status);
    }

    public function test_trusted_identity_creates_desk_customer_correctly(): void
    {
        $response = $this->resolveVerifiedEmail(self::RDIN_SITE, '200003', self::E2_EMAIL, 'idem-e2-customer')
            ->assertCreated();

        $this->assertDatabaseHas('central_customers', [
            'id' => (string) $response->json('desk_customer_id'),
            'central_wallet_id' => (string) $response->json('central_wallet_id'),
        ]);
    }

    public function test_trusted_identity_creates_cwid_correctly(): void
    {
        $response = $this->resolveVerifiedEmail(self::RDIN_SITE, '200004', self::E2_EMAIL, 'idem-e2-cwid')
            ->assertCreated();

        $this->assertDatabaseHas('central_wallets', [
            'id' => (string) $response->json('central_wallet_id'),
            'status' => 'active',
        ]);
    }

    public function test_correct_site_link_is_created_once(): void
    {
        $response = $this->resolveVerifiedEmail(self::RDIN_SITE, '200005', self::E2_EMAIL, 'idem-e2-link')
            ->assertCreated();

        $this->assertSame(
            1,
            CentralWalletAccountLink::query()
                ->where('site_code', self::RDIN_SITE)
                ->where('local_user_id', '200005')
                ->where('status', AccountLinkStatus::Active)
                ->count(),
        );

        $this->resolveVerifiedEmail(self::RDIN_SITE, '200005', self::E2_EMAIL, 'idem-e2-link-replay')
            ->assertOk()
            ->assertJsonPath('desk_customer_id', $response->json('desk_customer_id'));
    }

    public function test_repeated_verification_is_idempotent(): void
    {
        $first = $this->resolveVerifiedEmail(self::RDIN_SITE, '200006', self::E2_EMAIL, 'idem-e2-idem-1')
            ->assertCreated();

        $this->resolveVerifiedEmail(self::RDIN_SITE, '200006', self::E2_EMAIL, 'idem-e2-idem-2')
            ->assertOk()
            ->assertJsonPath('desk_customer_id', $first->json('desk_customer_id'))
            ->assertJsonPath('central_wallet_id', $first->json('central_wallet_id'));

        $this->assertSame(1, CentralCustomerIdentityCredential::query()->count());
    }

    public function test_ambiguous_identity_fails_closed(): void
    {
        app(E2HistoricalSettlementJournalImportService::class)->import();

        $existingCwid = (string) Str::uuid();
        $existingCustomer = (string) Str::uuid();
        $this->seedWallet($existingCwid);
        \DB::table('central_customers')->insert([
            'id' => $existingCustomer,
            'central_wallet_id' => $existingCwid,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        CentralWalletRefundMigration::query()
            ->where('refund_id', 91001)
            ->update([
                'desk_customer_id' => $existingCustomer,
                'cwid' => $existingCwid,
            ]);

        $this->resolveVerifiedEmail(self::RDIN_SITE, '200007', self::E2_EMAIL, 'idem-e2-ambiguous')
            ->assertCreated();

        $migration = CentralWalletRefundMigration::query()->where('refund_id', 91001)->firstOrFail();
        $this->assertSame($existingCwid, $migration->cwid);
        $this->assertNotSame(RefundMigrationStatus::Prepared, $migration->status);
    }

    public function test_verification_does_not_execute_lane4(): void
    {
        $this->resolveVerifiedEmail(self::RDIN_SITE, '200008', self::E2_EMAIL, 'idem-e2-no-lane4')
            ->assertCreated();

        $this->assertSame(0, CentralWalletLedgerEntry::query()->count());
        $this->assertTrue(
            CentralWalletRefundMigration::query()
                ->whereIn('refund_id', [91001, 91002])
                ->where('status', RefundMigrationStatus::CwCredited)
                ->doesntExist(),
        );
    }

    public function test_verification_does_not_create_ledger_entries(): void
    {
        $before = CentralWalletLedgerEntry::query()->count();
        $this->resolveVerifiedEmail(self::RDIN_SITE, '200009', self::E2_EMAIL, 'idem-e2-no-ledger')
            ->assertCreated();
        $this->assertSame($before, CentralWalletLedgerEntry::query()->count());
    }

    public function test_verification_does_not_modify_historical_refund_status(): void
    {
        app(E2HistoricalSettlementJournalImportService::class)->import();
        $statusesBefore = CentralWalletRefundMigration::query()
            ->whereIn('refund_id', [91001, 91002])
            ->pluck('status', 'refund_id')
            ->all();

        $this->resolveVerifiedEmail(self::RDIN_SITE, '200010', self::E2_EMAIL, 'idem-e2-no-refund-mutation')
            ->assertCreated();

        foreach ([91001, 91002] as $refundId) {
            $migration = CentralWalletRefundMigration::query()->where('refund_id', $refundId)->firstOrFail();
            $this->assertNotSame(RefundMigrationStatus::Reconciled, $migration->status);
            $this->assertNotSame(RefundMigrationStatus::CwCredited, $migration->status);
            if (isset($statusesBefore[$refundId]) && $statusesBefore[$refundId] === RefundMigrationStatus::Pending) {
                $this->assertSame(RefundMigrationStatus::Prepared, $migration->status);
            }
        }
    }

    public function test_existing_verified_user_identity_behavior_unchanged_outside_e2_cohort(): void
    {
        $this->resolveVerifiedEmail(self::RDIN_SITE, '999999', 'outside@example.com', 'idem-outside-e2')
            ->assertCreated();

        $this->assertSame(
            0,
            CentralWalletRefundMigration::query()->whereNotNull('desk_customer_id')->count(),
        );
    }

    public function test_e2_cohort_tracking_correctly_advances_state(): void
    {
        $resolver = app(E2CohortStateResolver::class);
        $before = $resolver->audit(self::COHORT_FIXTURE);
        $this->assertSame(3, $before['totals'][E2SettlementDestinationState::Unverified->value]['count']);

        $this->resolveVerifiedEmail(self::RDIN_SITE, '200011', self::E2_EMAIL, 'idem-e2-state')
            ->assertCreated();

        $after = $resolver->audit(self::COHORT_FIXTURE);
        $this->assertSame(2, $after['totals'][E2SettlementDestinationState::SettlementDestinationReady->value]['count']);
        $this->assertSame(1, $after['totals'][E2SettlementDestinationState::Unverified->value]['count']);
    }

    public function test_ceremony_foundation_can_prepare_e2_when_verified_email_exists(): void
    {
        app(E2HistoricalSettlementJournalImportService::class)->import();

        $cwid = (string) Str::uuid();
        $this->seedWallet($cwid);
        $proof = CeremonyVerificationProofValidator::issueForTesting(
            siteCode: self::RDIN_SITE,
            localUserId: '200012',
            ceremonyAttemptId: (string) Str::uuid(),
            verifiedPhoneE164Hash: hash('sha256', '+919111111111'),
            secret: self::CEREMONY_SECRET,
        );

        $this->withHeaders($this->authHeaders(self::RDIN_SITE))
            ->postJson('/api/central-wallet/v1/ceremony/complete', [
                'idempotency_key' => 'idem-e2-ceremony',
                'site_code' => self::RDIN_SITE,
                'local_user_id' => '200012',
                'ceremony_verification_ref' => $proof,
            ])
            ->assertCreated();

        CentralWalletCeremonyIdentity::query()
            ->where('site_code', self::RDIN_SITE)
            ->where('local_user_id', '200012')
            ->update(['central_wallet_id' => $cwid]);

        $customerId = (string) Str::uuid();
        \DB::table('central_customers')->insert([
            'id' => $customerId,
            'central_wallet_id' => $cwid,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        CentralCustomerIdentityCredential::query()->create([
            'desk_customer_id' => $customerId,
            'credential_type' => 'verified_email',
            'provider' => 'desk_email',
            'subject_hash' => hash('sha256', 'verified_email:'.self::E2_EMAIL),
            'verified_at' => now(),
        ]);
        CentralWalletAccountLink::query()
            ->where('site_code', self::RDIN_SITE)
            ->where('local_user_id', '200012')
            ->update(['desk_customer_id' => $customerId, 'central_wallet_id' => $cwid]);

        app(CustomerFoundationFromCeremonyService::class)->establishFromCeremony(
            self::RDIN_SITE,
            '200012',
            'test',
        );

        $this->assertTrue(
            CentralWalletRefundMigration::query()
                ->whereIn('refund_id', [91001, 91002])
                ->where('status', RefundMigrationStatus::Prepared)
                ->exists(),
        );
    }

    private function provisionalResolve(
        string $site,
        string $localUserId,
        string $email,
        string $idempotencyKey,
    ): TestResponse {
        return $this->withHeaders($this->authHeaders($site))
            ->postJson('/api/central-wallet/v1/customer-identity/provisional-resolve', [
                'idempotency_key' => $idempotencyKey,
                'site_code' => $site,
                'local_user_id' => $localUserId,
                'email' => $email,
                'email_verified' => false,
            ]);
    }

    private function resolveVerifiedEmail(
        string $site,
        string $localUserId,
        string $email,
        string $idempotencyKey,
    ): TestResponse {
        return $this->withHeaders($this->authHeaders($site))
            ->postJson('/api/central-wallet/v1/customer-identity/resolve', [
                'idempotency_key' => $idempotencyKey,
                'site_code' => $site,
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
    private function authHeaders(string $site): array
    {
        return [
            'Authorization' => 'Bearer '.self::TOKEN,
            'X-Site-Code' => $site,
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
