<?php

namespace Tests\Feature\CentralWallet;

use App\CentralWallet\Application\E1DestinationReadinessManifestService;
use App\CentralWallet\Application\E1IdentityMigrationJournalImportService;
use App\CentralWallet\Domain\Enums\E1IdentityState;
use App\CentralWallet\Domain\Enums\E1MigrationDestinationState;
use App\CentralWallet\Domain\Enums\RefundMigrationStatus;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletRefundMigration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class E1DestinationReadinessManifestTest extends TestCase
{
    use RefreshDatabase;

    private const COHORT_FIXTURE = __DIR__.'/../../fixtures/cw-e1-verification-cohort-test-fixture.json';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'central_wallet.e1_identity_migration.verification_cohort_manifest_path' => self::COHORT_FIXTURE,
            'central_wallet.e1_identity_migration.expected_count' => 3,
            'central_wallet.e1_identity_migration.expected_amount' => '1500.00',
            'central_wallet.refund_migration.execution_enabled' => false,
        ]);
    }

    public function test_builds_destination_readiness_manifest_for_unverified_e1_cohort(): void
    {
        $manifest = app(E1DestinationReadinessManifestService::class)->build(self::COHORT_FIXTURE);

        $this->assertSame(3, $manifest['population']['count']);
        $this->assertSame('1500.00', $manifest['population']['amount']);
        $this->assertSame(0, $manifest['destination_ready_count']);
        $this->assertSame(3, $manifest['blocked_count']);
        $this->assertSame(
            3,
            $manifest['identity_totals'][E1IdentityState::VerificationAvailable->value]['count'],
        );
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $manifest['manifest_rows_sha256']);
        $this->assertFalse($manifest['financial_execution']);
    }

    public function test_manifest_reflects_destination_ready_after_e1_verification_assignment(): void
    {
        app(E1IdentityMigrationJournalImportService::class)->import();

        \DB::table('central_wallets')->insert([
            'id' => '33333333-3333-4333-8333-333333333333',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \DB::table('central_customers')->insert([
            'id' => '44444444-4444-4444-8444-444444444444',
            'central_wallet_id' => '33333333-3333-4333-8333-333333333333',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \DB::table('central_wallet_account_links')->insert([
            'site_code' => 'rdservice.in',
            'local_user_id' => '400001',
            'desk_customer_id' => '44444444-4444-4444-8444-444444444444',
            'central_wallet_id' => '33333333-3333-4333-8333-333333333333',
            'status' => 'active',
            'verification_method' => 'verified_email',
            'linked_at' => now(),
            'created_by' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        CentralWalletRefundMigration::query()
            ->where('refund_id', 92001)
            ->update([
                'status' => RefundMigrationStatus::Prepared,
                'desk_customer_id' => '44444444-4444-4444-8444-444444444444',
                'cwid' => '33333333-3333-4333-8333-333333333333',
                'metadata' => [
                    'e1_verification' => [
                        'state' => E1MigrationDestinationState::MigrationDestinationReady->value,
                        'prepared_at' => '2026-10-01T12:00:00+00:00',
                        'verification_method' => 'verified_email',
                        'evidence' => 'trusted_credential_and_active_account_link',
                    ],
                ],
            ]);

        $manifest = app(E1DestinationReadinessManifestService::class)->build(self::COHORT_FIXTURE);
        $row = collect($manifest['destination_ready_rows'])->firstWhere('refund_id', 92001);

        $this->assertNotNull($row);
        $this->assertTrue($row['destination_ready']);
        $this->assertSame('verified_email', $row['verification_method']);
        $this->assertSame(1, $manifest['destination_ready_count']);
    }

    public function test_manifest_build_does_not_create_ledger_entries(): void
    {
        $before = CentralWalletLedgerEntry::query()->count();
        app(E1DestinationReadinessManifestService::class)->build(self::COHORT_FIXTURE);
        $this->assertSame($before, CentralWalletLedgerEntry::query()->count());
    }
}
