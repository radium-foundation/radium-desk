<?php

namespace Tests\Feature\CentralWallet;

use App\CentralWallet\Application\BalanceMigrationCutoverService;
use App\CentralWallet\Application\Contracts\WalletMigrationSpokeClient;
use App\CentralWallet\Application\PilotRefundMigrationJournalImportService;
use App\CentralWallet\Application\PilotRefundMigrationManifestLoader;
use App\CentralWallet\Application\PilotRefundMigrationOrchestrator;
use App\CentralWallet\Application\RefundMigrationLane1Executor;
use App\CentralWallet\Application\RefundMigrationRollbackService;
use App\CentralWallet\Domain\Enums\LedgerEntryType;
use App\CentralWallet\Domain\Enums\RefundMigrationLane;
use App\CentralWallet\Domain\Enums\RefundMigrationStatus;
use App\CentralWallet\Domain\RefundMigrationIdempotencyKey;
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

class PilotRefundMigrationEngineTest extends TestCase
{
    use RefreshDatabase;

    private FakeWalletMigrationSpokeClient $spoke;

    private string $manifestPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->spoke = new FakeWalletMigrationSpokeClient;
        $this->app->singleton(WalletMigrationSpokeClient::class, fn (): FakeWalletMigrationSpokeClient => $this->spoke);
        $this->app->forgetInstance(BalanceMigrationCutoverService::class);
        $this->app->forgetInstance(RefundMigrationLane1Executor::class);
        $this->app->forgetInstance(RefundMigrationRollbackService::class);
        $this->app->forgetInstance(PilotRefundMigrationOrchestrator::class);

        $this->manifestPath = base_path('tests/fixtures/cw-pilot-refund-ref-67392-manifest.json');

        config([
            'central_wallet.enabled' => true,
            'central_wallet.balance_migration.execution_enabled' => true,
            'central_wallet.refund_migration.execution_enabled' => true,
            'central_wallet.pilot_refund_migration.manifest_path' => $this->manifestPath,
            'central_wallet.pilot_refund_migration.allowed_refund_ids' => '387',
            'central_wallet.pilot_refund_migration.required_owner_approval_ref' => 'OWNER-PILOT-TEST',
        ]);
    }

    public function test_manifest_loader_accepts_ref_67392_fixture(): void
    {
        $manifest = app(PilotRefundMigrationManifestLoader::class)->load($this->manifestPath);

        $this->assertSame(1, $manifest['refund_count']);
        $this->assertSame([387], $manifest['allowed_refund_ids']);
    }

    public function test_manifest_hash_mismatch_is_rejected(): void
    {
        config(['central_wallet.pilot_refund_migration.manifest_rows_sha256' => 'deadbeef']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('pilot_refund_migration_config_hash_mismatch');

        app(PilotRefundMigrationManifestLoader::class)->load($this->manifestPath);
    }

    public function test_import_creates_prepared_row_without_account_link(): void
    {
        $this->seedRef67392LiveData();

        $result = app(PilotRefundMigrationJournalImportService::class)->import($this->manifestPath);

        $this->assertSame(1, $result['imported']);
        $this->assertDatabaseHas('central_wallet_refund_migrations', [
            'refund_id' => 387,
            'refund_reference' => 'REF-67392',
            'status' => RefundMigrationStatus::Prepared->value,
            'desk_customer_id' => '90f0b408-f874-40e1-927b-6ce41cee0aa9',
        ]);

        $migration = CentralWalletRefundMigration::query()->where('refund_id', 387)->firstOrFail();
        $this->assertSame('562976', $migration->metadata['order_resolved_user_id'] ?? null);
    }

    public function test_preflight_passes_for_ref_67392_fixture(): void
    {
        $this->seedRef67392LiveData();
        app(PilotRefundMigrationJournalImportService::class)->import($this->manifestPath);

        $result = app(PilotRefundMigrationOrchestrator::class)->preflight($this->manifestPath);

        $this->assertTrue($result['ready'], json_encode($result['blockers'], JSON_THROW_ON_ERROR));
        $this->assertSame([], array_filter(
            $result['blockers'],
            static fn (array $row): bool => ($row['code'] ?? '') !== 'already_reconciled',
        ));
    }

    public function test_lane1_executes_desk_credit_and_spoke_retirement(): void
    {
        $customer = $this->seedRef67392LiveData();
        app(PilotRefundMigrationJournalImportService::class)->import($this->manifestPath);
        $migration = CentralWalletRefundMigration::query()->where('refund_id', 387)->firstOrFail();

        $result = app(RefundMigrationLane1Executor::class)->execute(
            $migration,
            'OWNER-PILOT-TEST',
            (string) Str::uuid(),
            'test',
        );

        $this->assertSame(RefundMigrationStatus::Reconciled->value, $result['status']);
        $this->assertCount(1, $this->spoke->retireCalls);
        $this->assertDatabaseHas('central_wallet_ledger_entries', [
            'central_wallet_id' => $customer->central_wallet_id,
            'amount' => '499.00',
            'business_reference' => 'REF-67392',
            'source_reference' => 'users_wallet:2663',
        ]);
    }

    public function test_execute_is_idempotent_when_already_reconciled(): void
    {
        $this->seedRef67392LiveData();
        app(PilotRefundMigrationJournalImportService::class)->import($this->manifestPath);
        $migration = CentralWalletRefundMigration::query()->where('refund_id', 387)->firstOrFail();

        app(RefundMigrationLane1Executor::class)->execute($migration, 'OWNER-PILOT-TEST', (string) Str::uuid(), 'test');
        $migration->refresh();

        $replay = app(PilotRefundMigrationOrchestrator::class)->executeSingle(
            $migration,
            'OWNER-PILOT-TEST',
            $this->manifestPath,
        );

        $this->assertTrue($replay['idempotent_replay'] ?? false);
        $this->assertSame(1, CentralWalletLedgerEntry::query()->where('entry_type', LedgerEntryType::Credit)->count());
    }

    public function test_execution_blocked_when_flags_disabled(): void
    {
        config(['central_wallet.refund_migration.execution_enabled' => false]);
        $this->seedRef67392LiveData();
        app(PilotRefundMigrationJournalImportService::class)->import($this->manifestPath);
        $migration = CentralWalletRefundMigration::query()->where('refund_id', 387)->firstOrFail();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('refund_migration_execution_disabled');

        app(PilotRefundMigrationOrchestrator::class)->executeSingle($migration, 'OWNER-PILOT-TEST', $this->manifestPath);
    }

    public function test_preflight_blocks_existing_desk_ledger_credit(): void
    {
        $customer = $this->seedRef67392LiveData();
        CentralWalletLedgerEntry::query()->create([
            'central_wallet_id' => $customer->central_wallet_id,
            'entry_type' => LedgerEntryType::Credit,
            'amount' => '499.00',
            'currency' => 'INR',
            'status' => 'posted',
            'source_system' => 'radium-desk',
            'source_reference' => 'manual',
            'correlation_id' => (string) Str::uuid(),
            'business_reference' => 'REF-67392',
            'posted_at' => now(),
        ]);

        app(PilotRefundMigrationJournalImportService::class)->import($this->manifestPath);

        $result = app(PilotRefundMigrationOrchestrator::class)->preflight($this->manifestPath);

        $this->assertFalse($result['ready']);
        $this->assertTrue(collect($result['blockers'])->contains(
            fn (array $b): bool => $b['code'] === 'duplicate_ledger_credit',
        ));
    }

    public function test_spoke_retirement_failure_marks_compensating(): void
    {
        $this->seedRef67392LiveData();
        app(PilotRefundMigrationJournalImportService::class)->import($this->manifestPath);
        $migration = CentralWalletRefundMigration::query()->where('refund_id', 387)->firstOrFail();

        $this->spoke->retireShouldFail = true;

        try {
            app(RefundMigrationLane1Executor::class)->execute($migration, 'OWNER-PILOT-TEST', (string) Str::uuid(), 'test');
            $this->fail('Expected retirement failure');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('source_retirement_failed', $exception->getMessage());
        }

        $this->assertSame(
            RefundMigrationStatus::Compensating->value,
            $migration->fresh()->status->value,
        );
    }

    public function test_rollback_reverses_lane1_and_restores_spoke_idempotently(): void
    {
        $this->seedRef67392LiveData();
        app(PilotRefundMigrationJournalImportService::class)->import($this->manifestPath);
        $migration = CentralWalletRefundMigration::query()->where('refund_id', 387)->firstOrFail();

        app(RefundMigrationLane1Executor::class)->execute($migration, 'OWNER-PILOT-TEST', (string) Str::uuid(), 'test');
        $migration->refresh();

        $rollback = app(RefundMigrationRollbackService::class);
        $first = $rollback->rollback($migration, 'OWNER-PILOT-TEST', (string) Str::uuid(), 'test');
        $migration->refresh();
        $second = $rollback->rollback($migration, 'OWNER-PILOT-TEST', (string) Str::uuid(), 'test');

        $this->assertSame(RefundMigrationStatus::Reversed->value, $first['status']);
        $this->assertTrue($second['idempotent_replay'] ?? false);
        $this->assertCount(1, $this->spoke->restoreCalls);
        $this->assertSame(1, CentralWalletLedgerEntry::query()->where('entry_type', LedgerEntryType::Reversal)->count());
    }

    public function test_rollback_spoke_restore_failure_marks_reconciliation_required(): void
    {
        $this->seedRef67392LiveData();
        app(PilotRefundMigrationJournalImportService::class)->import($this->manifestPath);
        $migration = CentralWalletRefundMigration::query()->where('refund_id', 387)->firstOrFail();

        app(RefundMigrationLane1Executor::class)->execute($migration, 'OWNER-PILOT-TEST', (string) Str::uuid(), 'test');
        $migration->refresh();

        $this->spoke->restoreShouldFail = true;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('spoke_restore_failed');

        app(RefundMigrationRollbackService::class)->rollback($migration, 'OWNER-PILOT-TEST', (string) Str::uuid(), 'test');

        $this->assertSame(
            RefundMigrationStatus::ReconciliationRequired->value,
            $migration->fresh()->status->value,
        );
    }

    public function test_duplicate_refund_id_import_is_skipped(): void
    {
        $this->seedRef67392LiveData();
        CentralWalletRefundMigration::query()->create([
            'id' => (string) Str::uuid(),
            'batch_id' => 'existing',
            'refund_id' => 387,
            'refund_reference' => 'REF-67392',
            'amount' => '499.00',
            'source_type' => 'spoke_wallet',
            'source_application' => 'rdservice.in',
            'source_wallet_id' => 2663,
            'source_reference' => RefundMigrationIdempotencyKey::ledgerSourceReference(387),
            'lane' => RefundMigrationLane::Lane1SpokeCutover,
            'status' => RefundMigrationStatus::Prepared,
            'idempotency_key' => RefundMigrationIdempotencyKey::forRefund(387),
        ]);

        $result = app(PilotRefundMigrationJournalImportService::class)->import($this->manifestPath);

        $this->assertSame(0, $result['imported']);
        $this->assertSame(1, $result['skipped']);
    }

    private function seedRef67392LiveData(): CentralCustomer
    {
        $cwid = '8946cd5e-208d-420f-b922-f5727bc47a87';
        CentralWallet::query()->forceCreate([
            'id' => $cwid,
            'status' => 'active',
        ]);

        $customer = CentralCustomer::query()->create([
            'id' => '90f0b408-f874-40e1-927b-6ce41cee0aa9',
            'central_wallet_id' => $cwid,
            'status' => 'active',
        ]);

        $orderId = 62567;
        DB::table('orders')->insert([
            'id' => $orderId,
            'order_id' => 'RD16854',
            'customer_id' => $customer->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::factory()->create();

        RefundRequest::query()->forceCreate([
            'id' => 387,
            'order_id' => $orderId,
            'reference_no' => 'REF-67392',
            'amount' => '499.00',
            'refund_amount' => '499.00',
            'reason' => 'Pilot migration test fixture.',
            'approved_refund_method' => ApprovedRefundMethod::Wallet,
            'status' => RefundStatus::Closed,
            'execution_transaction_id' => '2663',
            'requested_by' => $user->id,
            'communication_channels' => [],
        ]);

        return $customer;
    }
}
