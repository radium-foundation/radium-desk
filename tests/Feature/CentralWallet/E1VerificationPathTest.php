<?php

namespace Tests\Feature\CentralWallet;

use App\CentralWallet\Application\E1CohortStateResolver;
use App\CentralWallet\Application\E1IdentityMigrationJournalImportService;
use App\CentralWallet\Domain\Enums\AccountLinkStatus;
use App\CentralWallet\Domain\Enums\E1IdentityState;
use App\CentralWallet\Domain\Enums\E1MigrationDestinationState;
use App\CentralWallet\Domain\Enums\RefundMigrationLane;
use App\CentralWallet\Domain\Enums\RefundMigrationStatus;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomerIdentityCredential;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletAccountLink;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletRefundMigration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class E1VerificationPathTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'cw-e1-verification-token';

    private const RDIN_SITE = 'rdservice.in';

    private const E1_EMAIL = 'e1user@example.com';

    private const COHORT_FIXTURE = __DIR__.'/../../fixtures/cw-e1-verification-cohort-test-fixture.json';

    private const E2_COHORT_FIXTURE = __DIR__.'/../../fixtures/cw-e2-verification-cohort-test-fixture.json';

    private const E2_SETTLEMENT_FIXTURE = __DIR__.'/../../fixtures/cw-e2-verification-settlement-test-fixture.json';

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
            'central_wallet.identity_required_cohort.provisional_display_enabled' => true,
            'central_wallet.identity_required_cohort.campaign_manifest_path' => __DIR__.'/../../fixtures/cw-identity-required-cohort-test-fixture.json',
            'central_wallet.identity_required_cohort.expected_refunds' => 7,
            'central_wallet.identity_required_cohort.expected_amount' => '2150.00',
            'central_wallet.e1_identity_migration.verification_enabled' => true,
            'central_wallet.e1_identity_migration.verification_cohort_manifest_path' => self::COHORT_FIXTURE,
            'central_wallet.e1_identity_migration.expected_count' => 3,
            'central_wallet.e1_identity_migration.expected_amount' => '1500.00',
            'central_wallet.e2_historical_settlement.verification_enabled' => true,
            'central_wallet.e2_historical_settlement.verification_cohort_manifest_path' => self::E2_COHORT_FIXTURE,
            'central_wallet.e2_historical_settlement.manifest_path' => self::E2_SETTLEMENT_FIXTURE,
            'central_wallet.e2_historical_settlement.expected_count' => 3,
            'central_wallet.e2_historical_settlement.expected_amount' => '1714.00',
            'central_wallet.refund_migration.execution_enabled' => false,
            'central_wallet.e2_historical_settlement.execution_enabled' => false,
        ]);
    }

    public function test_e1_cohort_audit_shows_verification_available_before_trusted_identity(): void
    {
        $audit = app(E1CohortStateResolver::class)->audit(self::COHORT_FIXTURE);

        $this->assertSame(3, $audit['population']['count']);
        $this->assertSame(
            3,
            $audit['identity_totals'][E1IdentityState::VerificationAvailable->value]['count'],
        );
        $this->assertSame(
            3,
            $audit['destination_totals'][E1MigrationDestinationState::Unverified->value]['count'],
        );
    }

    public function test_unverified_email_cannot_establish_trusted_identity(): void
    {
        $this->withHeaders($this->authHeaders(self::RDIN_SITE))
            ->postJson('/api/central-wallet/v1/customer-identity/resolve', [
                'idempotency_key' => 'idem-e1-unverified',
                'site_code' => self::RDIN_SITE,
                'local_user_id' => '400001',
                'identity' => [
                    'type' => 'email',
                    'email' => self::E1_EMAIL,
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'unsupported_identity_type');
    }

    public function test_successful_verified_email_establishes_e1_destination_without_ledger_credit(): void
    {
        $beforeLedger = CentralWalletLedgerEntry::query()->count();

        $this->resolveVerifiedEmail(self::RDIN_SITE, '400001', self::E1_EMAIL, 'idem-e1-email')
            ->assertCreated();

        $migration92001 = CentralWalletRefundMigration::query()->where('refund_id', 92001)->firstOrFail();
        $migration92002 = CentralWalletRefundMigration::query()->where('refund_id', 92002)->firstOrFail();

        $this->assertSame(RefundMigrationStatus::Prepared, $migration92001->status);
        $this->assertSame(RefundMigrationStatus::Prepared, $migration92002->status);
        $this->assertNotNull($migration92001->desk_customer_id);
        $this->assertNotNull($migration92001->cwid);
        $this->assertSame(
            E1MigrationDestinationState::MigrationDestinationReady->value,
            $migration92001->metadata['e1_verification']['state'],
        );
        $this->assertSame($beforeLedger, CentralWalletLedgerEntry::query()->count());
    }

    public function test_repeated_verification_is_idempotent_for_e1(): void
    {
        $first = $this->resolveVerifiedEmail(self::RDIN_SITE, '400001', self::E1_EMAIL, 'idem-e1-idem-1')
            ->assertCreated();

        $this->resolveVerifiedEmail(self::RDIN_SITE, '400001', self::E1_EMAIL, 'idem-e1-idem-2')
            ->assertOk()
            ->assertJsonPath('desk_customer_id', $first->json('desk_customer_id'));

        $this->assertSame(1, CentralCustomerIdentityCredential::query()->count());
        $this->assertSame(
            1,
            CentralWalletAccountLink::query()
                ->where('site_code', self::RDIN_SITE)
                ->where('local_user_id', '400001')
                ->where('status', AccountLinkStatus::Active)
                ->count(),
        );
    }

    public function test_preassigned_cwid_conflict_blocks_e1_destination_preparation(): void
    {
        app(E1IdentityMigrationJournalImportService::class)->import();

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
            ->where('refund_id', 92001)
            ->update([
                'desk_customer_id' => $existingCustomer,
                'cwid' => $existingCwid,
            ]);

        $this->resolveVerifiedEmail(self::RDIN_SITE, '400001', self::E1_EMAIL, 'idem-e1-ambiguous')
            ->assertCreated();

        $migration = CentralWalletRefundMigration::query()->where('refund_id', 92001)->firstOrFail();
        $this->assertSame($existingCwid, $migration->cwid);
        $this->assertNotSame(RefundMigrationStatus::Prepared, $migration->status);
    }

    public function test_e2_path_still_works_alongside_e1_hooks(): void
    {
        $this->withHeaders($this->authHeaders(self::RDIN_SITE))
            ->postJson('/api/central-wallet/v1/customer-identity/resolve', [
                'idempotency_key' => 'idem-e2-regression',
                'site_code' => self::RDIN_SITE,
                'local_user_id' => '200001',
                'identity' => [
                    'type' => 'verified_email',
                    'email' => 'e2user@example.com',
                ],
            ])
            ->assertCreated();

        $migration = CentralWalletRefundMigration::query()->where('refund_id', 91001)->firstOrFail();
        $this->assertSame('SETTLEMENT_DESTINATION_READY', $migration->metadata['e2_verification']['state']);
    }

    public function test_reconciled_refund_regression_not_mutated(): void
    {
        CentralWalletRefundMigration::query()->create([
            'id' => '00000000-0000-4000-8000-000000000360',
            'batch_id' => 'desk-refund-wallet-migration-refund360-p30-10-26',
            'refund_id' => 360,
            'refund_reference' => 'REF-67363',
            'amount' => '849.00',
            'source_type' => 'spoke_wallet',
            'source_application' => 'radiumbox.com',
            'source_wallet_id' => 2567,
            'source_reference' => 'desk-refund-migration:refund_requests:360',
            'lane' => RefundMigrationLane::Lane1SpokeCutover,
            'status' => RefundMigrationStatus::Reconciled,
            'idempotency_key' => 'desk-refund-migration:refund_requests:360',
            'prepared_at' => now(),
            'metadata' => [],
        ]);

        $this->resolveVerifiedEmail(self::RDIN_SITE, '400001', self::E1_EMAIL, 'idem-e1-reconciled-regression')
            ->assertCreated();

        $reconciled = CentralWalletRefundMigration::query()->where('refund_id', 360)->firstOrFail();
        $this->assertSame(RefundMigrationStatus::Reconciled, $reconciled->status);
    }

    /**
     * @return array<string, string>
     */
    private function authHeaders(string $siteCode): array
    {
        return [
            'Authorization' => 'Bearer '.self::TOKEN,
            'X-Site-Code' => $siteCode,
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

    private function resolveVerifiedEmail(
        string $siteCode,
        string $localUserId,
        string $email,
        string $idempotencyKey,
    ): TestResponse {
        return $this->withHeaders($this->authHeaders($siteCode))
            ->postJson('/api/central-wallet/v1/customer-identity/resolve', [
                'idempotency_key' => $idempotencyKey,
                'site_code' => $siteCode,
                'local_user_id' => $localUserId,
                'identity' => [
                    'type' => 'verified_email',
                    'email' => $email,
                ],
            ]);
    }
}
