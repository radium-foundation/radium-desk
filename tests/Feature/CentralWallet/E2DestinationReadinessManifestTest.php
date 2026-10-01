<?php

namespace Tests\Feature\CentralWallet;

use App\CentralWallet\Application\E2DestinationReadinessManifestService;
use App\CentralWallet\Application\E2HistoricalSettlementJournalImportService;
use App\CentralWallet\Domain\Enums\E2SettlementDestinationState;
use App\CentralWallet\Domain\Enums\RefundMigrationLane;
use App\CentralWallet\Domain\Enums\RefundMigrationStatus;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletRefundMigration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class E2DestinationReadinessManifestTest extends TestCase
{
    use RefreshDatabase;

    private const COHORT_FIXTURE = __DIR__.'/../../fixtures/cw-e2-verification-cohort-test-fixture.json';

    private const SETTLEMENT_FIXTURE = __DIR__.'/../../fixtures/cw-e2-verification-settlement-test-fixture.json';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'central_wallet.e2_historical_settlement.verification_cohort_manifest_path' => self::COHORT_FIXTURE,
            'central_wallet.e2_historical_settlement.manifest_path' => self::SETTLEMENT_FIXTURE,
            'central_wallet.e2_historical_settlement.expected_count' => 3,
            'central_wallet.e2_historical_settlement.expected_amount' => '1714.00',
            'central_wallet.e2_historical_settlement.execution_enabled' => false,
            'central_wallet.refund_migration.execution_enabled' => false,
        ]);
    }

    public function test_builds_destination_readiness_manifest_for_unverified_cohort(): void
    {
        $manifest = app(E2DestinationReadinessManifestService::class)->build(self::COHORT_FIXTURE);

        $this->assertSame(3, $manifest['population']['count']);
        $this->assertSame('1714.00', $manifest['population']['amount']);
        $this->assertSame(3, count($manifest['rows']));
        $this->assertSame(0, $manifest['destination_ready_count']);
        $this->assertSame(
            3,
            $manifest['destination_totals'][E2SettlementDestinationState::Unverified->value]['count'],
        );
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $manifest['manifest_rows_sha256']);
        $this->assertFalse($manifest['financial_execution']);
    }

    public function test_manifest_reflects_destination_ready_after_verification_assignment(): void
    {
        app(E2HistoricalSettlementJournalImportService::class)->import();

        \DB::table('central_wallets')->insert([
            'id' => '22222222-2222-4222-8222-222222222222',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \DB::table('central_customers')->insert([
            'id' => '11111111-1111-4111-8111-111111111111',
            'central_wallet_id' => '22222222-2222-4222-8222-222222222222',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \DB::table('central_wallet_account_links')->insert([
            'site_code' => 'rdservice.in',
            'local_user_id' => '200001',
            'desk_customer_id' => '11111111-1111-4111-8111-111111111111',
            'central_wallet_id' => '22222222-2222-4222-8222-222222222222',
            'status' => 'active',
            'verification_method' => 'verified_email',
            'linked_at' => now(),
            'created_by' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        CentralWalletRefundMigration::query()
            ->where('refund_id', 91001)
            ->update([
                'status' => RefundMigrationStatus::Prepared,
                'desk_customer_id' => '11111111-1111-4111-8111-111111111111',
                'cwid' => '22222222-2222-4222-8222-222222222222',
                'metadata' => [
                    'e2_verification' => [
                        'state' => 'SETTLEMENT_DESTINATION_READY',
                        'prepared_at' => '2026-10-01T12:00:00+00:00',
                        'verification_method' => 'verified_email',
                    ],
                ],
            ]);

        $manifest = app(E2DestinationReadinessManifestService::class)->build(self::COHORT_FIXTURE);
        $row = collect($manifest['rows'])->firstWhere('refund_id', 91001);

        $this->assertTrue($row['destination_ready']);
        $this->assertSame('verified_email', $row['verification_method']);
        $this->assertSame(1, $manifest['destination_ready_count']);
    }

    public function test_manifest_build_does_not_create_ledger_entries(): void
    {
        $before = CentralWalletLedgerEntry::query()->count();
        app(E2DestinationReadinessManifestService::class)->build(self::COHORT_FIXTURE);
        $this->assertSame($before, CentralWalletLedgerEntry::query()->count());
    }

    public function test_reconciled_refund_outside_e2_cohort_is_not_included_in_manifest(): void
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

        $manifest = app(E2DestinationReadinessManifestService::class)->build(self::COHORT_FIXTURE);

        $this->assertFalse(collect($manifest['rows'])->contains('refund_id', 360));
        $this->assertSame(3, count($manifest['rows']));
    }
}
