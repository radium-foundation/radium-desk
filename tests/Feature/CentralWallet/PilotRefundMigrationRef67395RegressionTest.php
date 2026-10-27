<?php

namespace Tests\Feature\CentralWallet;

use App\CentralWallet\Application\BalanceMigrationCutoverService;
use App\CentralWallet\Application\CentralWalletRefundLedgerCreditService;
use App\CentralWallet\Application\Contracts\WalletMigrationSpokeClient;
use App\CentralWallet\Application\LedgerService;
use App\CentralWallet\Application\PilotRefundMigrationJournalImportService;
use App\CentralWallet\Application\PilotRefundMigrationManifestLoader;
use App\CentralWallet\Application\PilotRefundMigrationOrchestrator;
use App\CentralWallet\Application\RefundMigrationLane1Executor;
use App\CentralWallet\Application\WalletRefundAccountingDiscrepancyClassifier;
use App\CentralWallet\Application\WalletRefundAuthoritativeCreditGuard;
use App\CentralWallet\Domain\Enums\LedgerEntryType;
use App\CentralWallet\Domain\Enums\RefundMigrationStatus;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralWallet;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletRefundMigration;
use App\Enums\ApprovedRefundMethod;
use App\Enums\RefundStatus;
use App\Models\RefundRequest;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\Support\FakeWalletMigrationSpokeClient;
use Tests\TestCase;

class PilotRefundMigrationRef67395RegressionTest extends TestCase
{
    use RefreshDatabase;

    private string $manifestPath;

    private FakeWalletMigrationSpokeClient $spoke;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->spoke = new FakeWalletMigrationSpokeClient;
        $this->app->instance(WalletMigrationSpokeClient::class, $this->spoke);
        $this->app->forgetInstance(BalanceMigrationCutoverService::class);
        $this->app->forgetInstance(RefundMigrationLane1Executor::class);
        $this->app->forgetInstance(PilotRefundMigrationOrchestrator::class);

        $this->manifestPath = base_path('tests/fixtures/cw-pilot-refund-ref-67395-manifest.json');

        config([
            'central_wallet.enabled' => true,
            'central_wallet.balance_migration.execution_enabled' => false,
            'central_wallet.refund_migration.execution_enabled' => false,
            'central_wallet.pilot_refund_migration.manifest_path' => $this->manifestPath,
            'central_wallet.pilot_refund_migration.allowed_refund_ids' => '390',
            'central_wallet.pilot_refund_migration.required_owner_approval_ref' => 'OWNER-PILOT-REF-67395-TEST',
        ]);
    }

    public function test_ref_67395_manifest_matches_production_regression_fingerprint(): void
    {
        $manifest = app(PilotRefundMigrationManifestLoader::class)->load($this->manifestPath);

        $this->assertSame([390], $manifest['allowed_refund_ids']);
        $this->assertSame('REF-67395', $manifest['rows'][0]['desk_refund_reference']);
        $this->assertSame('052076aa8f61071e68d46b204f6bdee9c560a98a6fe99841c77f45b6df1a5305', $manifest['manifest_rows_sha256']);
    }

    public function test_ref_67395_manifest_imports_journal_row_without_execution(): void
    {
        $this->seedRef67395RegressionData();

        $result = app(PilotRefundMigrationJournalImportService::class)->import($this->manifestPath);

        $this->assertSame(1, $result['imported'] ?? 0);
        $this->assertDatabaseHas('central_wallet_refund_migrations', [
            'refund_id' => 390,
            'refund_reference' => 'REF-67395',
        ]);
    }

    public function test_ref_67395_authority_gap_is_detected_with_zero_desk_balance(): void
    {
        $this->seedRef67395RegressionData();
        $cwid = 'd1852026-d8c2-4af4-818c-d3151549f614';

        $gaps = app(WalletRefundAccountingDiscrepancyClassifier::class)
            ->authorityGapsForDeskOrder(62928);

        $this->assertCount(1, $gaps);
        $this->assertSame('REF-67395', $gaps[0]['reference_no']);
        $this->assertSame('597.00', number_format((float) $gaps[0]['refund_amount'], 2, '.', ''));
        $this->assertSame('0.00', app(LedgerService::class)->availableBalance($cwid));
    }

    public function test_ref_67395_standalone_desk_credit_blocked_while_legacy_pattern_unreconciled(): void
    {
        config(['central_wallet.wallet_refund.desk_first_enabled' => true]);
        $this->seedRef67395RegressionData();

        $refund = RefundRequest::query()->findOrFail(390);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('wallet_refund_requires_paired_reconciliation');

        app(CentralWalletRefundLedgerCreditService::class)->creditForRefund($refund, (string) Str::uuid());
    }

    public function test_ref_67395_paired_lane1_reconciliation_posts_single_desk_credit_and_retires_spoke(): void
    {
        $this->enableMigrationExecution();
        $this->seedRef67395RegressionData();
        app(PilotRefundMigrationJournalImportService::class)->import($this->manifestPath);
        $migration = CentralWalletRefundMigration::query()->where('refund_id', 390)->firstOrFail();
        $cwid = 'd1852026-d8c2-4af4-818c-d3151549f614';

        $this->assertSame('0.00', app(LedgerService::class)->availableBalance($cwid));

        $result = app(RefundMigrationLane1Executor::class)->execute(
            $migration,
            'OWNER-PILOT-REF-67395-TEST',
            (string) Str::uuid(),
            'test',
        );

        $this->assertSame(RefundMigrationStatus::Reconciled->value, $result['status']);
        $this->assertCount(1, $this->spoke->retireCalls);
        $this->assertSame('597.00', app(LedgerService::class)->availableBalance($cwid));
        $this->assertDatabaseHas('central_wallet_ledger_entries', [
            'central_wallet_id' => $cwid,
            'amount' => '597.00',
            'business_reference' => 'REF-67395',
            'source_reference' => 'users_wallet:2667',
        ]);
        $this->assertSame(1, CentralWalletLedgerEntry::query()->where('entry_type', LedgerEntryType::Credit)->count());
    }

    public function test_ref_67395_execute_retry_is_idempotent(): void
    {
        $this->enableMigrationExecution();
        $this->seedRef67395RegressionData();
        app(PilotRefundMigrationJournalImportService::class)->import($this->manifestPath);
        $migration = CentralWalletRefundMigration::query()->where('refund_id', 390)->firstOrFail();

        app(RefundMigrationLane1Executor::class)->execute($migration, 'OWNER-PILOT-REF-67395-TEST', (string) Str::uuid(), 'test');
        $migration->refresh();

        $replay = app(PilotRefundMigrationOrchestrator::class)->executeSingle(
            $migration,
            'OWNER-PILOT-REF-67395-TEST',
            $this->manifestPath,
        );

        $this->assertTrue($replay['idempotent_replay'] ?? false);
        $this->assertSame(1, CentralWalletLedgerEntry::query()->where('entry_type', LedgerEntryType::Credit)->count());
        $this->assertCount(1, $this->spoke->retireCalls);
    }

    public function test_ref_67395_spoke_retirement_failure_enters_compensating_state(): void
    {
        $this->enableMigrationExecution();
        $this->seedRef67395RegressionData();
        app(PilotRefundMigrationJournalImportService::class)->import($this->manifestPath);
        $migration = CentralWalletRefundMigration::query()->where('refund_id', 390)->firstOrFail();
        $cwid = 'd1852026-d8c2-4af4-818c-d3151549f614';

        $this->spoke->retireShouldFail = true;

        try {
            app(RefundMigrationLane1Executor::class)->execute($migration, 'OWNER-PILOT-REF-67395-TEST', (string) Str::uuid(), 'test');
            $this->fail('Expected retirement failure');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('source_retirement_failed', $exception->getMessage());
        }

        $this->assertSame(RefundMigrationStatus::Compensating->value, $migration->fresh()->status->value);
        $this->assertSame(1, CentralWalletLedgerEntry::query()->where('entry_type', LedgerEntryType::Credit)->count());

        config(['central_wallet.wallet_refund.desk_first_enabled' => true]);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('wallet_refund_requires_paired_reconciliation');
        app(CentralWalletRefundLedgerCreditService::class)->creditForRefund(RefundRequest::query()->findOrFail(390), (string) Str::uuid());
    }

    public function test_ref_67395_reconciled_refund_does_not_duplicate_on_second_migration_attempt(): void
    {
        $this->enableMigrationExecution();
        $this->seedRef67395RegressionData();
        app(PilotRefundMigrationJournalImportService::class)->import($this->manifestPath);
        $migration = CentralWalletRefundMigration::query()->where('refund_id', 390)->firstOrFail();

        app(RefundMigrationLane1Executor::class)->execute($migration, 'OWNER-PILOT-REF-67395-TEST', (string) Str::uuid(), 'test');
        $migration->refresh();

        $replay = app(RefundMigrationLane1Executor::class)->execute(
            $migration,
            'OWNER-PILOT-REF-67395-TEST',
            (string) Str::uuid(),
            'test',
        );

        $this->assertSame(RefundMigrationStatus::Reconciled->value, $replay['status']);
        $this->assertSame(1, CentralWalletLedgerEntry::query()->where('business_reference', 'REF-67395')->count());
    }

    private function enableMigrationExecution(): void
    {
        config([
            'central_wallet.balance_migration.execution_enabled' => true,
            'central_wallet.refund_migration.execution_enabled' => true,
        ]);
    }

    private function seedRef67395RegressionData(): void
    {
        $cwid = 'd1852026-d8c2-4af4-818c-d3151549f614';
        CentralWallet::query()->forceCreate([
            'id' => $cwid,
            'status' => 'active',
        ]);

        CentralCustomer::query()->create([
            'id' => 'c51247d0-bb8a-4924-8389-8503ba32f5bc',
            'central_wallet_id' => $cwid,
            'status' => 'active',
        ]);

        $orderId = 62928;
        DB::table('orders')->insert([
            'id' => $orderId,
            'order_id' => 'RD17641',
            'customer_id' => 'c51247d0-bb8a-4924-8389-8503ba32f5bc',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::factory()->create();

        RefundRequest::query()->forceCreate([
            'id' => 390,
            'order_id' => $orderId,
            'reference_no' => 'REF-67395',
            'amount' => '597.00',
            'refund_amount' => '597.00',
            'reason' => 'RD17641 regression fixture.',
            'approved_refund_method' => ApprovedRefundMethod::Wallet,
            'status' => RefundStatus::Closed,
            'execution_transaction_id' => '2667',
            'requested_by' => $user->id,
            'communication_channels' => [],
        ]);
    }
}
