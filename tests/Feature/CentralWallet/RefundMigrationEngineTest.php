<?php

namespace Tests\Feature\CentralWallet;

use App\CentralWallet\Application\BalanceMigrationCutoverService;
use App\CentralWallet\Application\Contracts\WalletMigrationSpokeClient;
use App\CentralWallet\Application\RefundMigrationBatchGate;
use App\CentralWallet\Application\RefundMigrationDryRunService;
use App\CentralWallet\Application\RefundMigrationJournalImportService;
use App\CentralWallet\Application\RefundMigrationLane1Executor;
use App\CentralWallet\Application\RefundMigrationManifestLoader;
use App\CentralWallet\Application\RefundMigrationOrchestrator;
use App\CentralWallet\Application\RefundMigrationRollbackService;
use App\CentralWallet\Application\RefundMigrationTargetAssignmentService;
use App\CentralWallet\Application\RefundProvenanceMigrationService;
use App\CentralWallet\Domain\Enums\RefundMigrationLane;
use App\CentralWallet\Domain\Enums\RefundMigrationStatus;
use App\CentralWallet\Domain\RefundMigrationIdempotencyKey;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralWallet;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletRefundMigration;
use App\Enums\ApprovedRefundMethod;
use App\Enums\RefundStatus;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\Support\FakeWalletMigrationSpokeClient;
use Tests\TestCase;

class RefundMigrationEngineTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'cw-refund-migration-token';

    private FakeWalletMigrationSpokeClient $spoke;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->spoke = new FakeWalletMigrationSpokeClient;
        $this->app->singleton(WalletMigrationSpokeClient::class, fn (): FakeWalletMigrationSpokeClient => $this->spoke);
        $this->app->forgetInstance(RefundMigrationLane1Executor::class);
        $this->app->forgetInstance(BalanceMigrationCutoverService::class);

        config([
            'central_wallet.enabled' => true,
            'central_wallet.api_enabled' => true,
            'central_wallet.integration_token' => self::TOKEN,
            'central_wallet.balance_migration.execution_enabled' => true,
            'central_wallet.refund_migration.execution_enabled' => true,
            'central_wallet.refund_migration.manifest_path' => base_path('tests/fixtures/cw-migration-manifest-test.json'),
        ]);
    }

    public function test_production_manifest_has_exact_292_rows_and_amount(): void
    {
        $loader = app(RefundMigrationManifestLoader::class);
        $manifest = $loader->load(base_path('storage/app/private/cw-migration-manifest-final-p30-09-25.json'));

        $this->assertSame(292, count($manifest['rows']));
        $this->assertSame('165708.00', $manifest['population_amount']);
        $this->assertSame(RefundMigrationManifestLoader::BATCH_ID, $manifest['batch_id']);
    }

    public function test_manifest_loader_rejects_count_mismatch(): void
    {
        $loader = app(RefundMigrationManifestLoader::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('refund_migration_manifest_count_mismatch');
        $loader->load(base_path('tests/fixtures/cw-migration-manifest-test.json'));
    }

    public function test_import_rejects_non_production_manifest(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(RefundMigrationJournalImportService::class)->import(
            base_path('tests/fixtures/cw-migration-manifest-test.json'),
        );
    }

    public function test_import_production_manifest_creates_292_journal_rows(): void
    {
        config([
            'central_wallet.refund_migration.manifest_path' => base_path(
                'storage/app/private/cw-migration-manifest-final-p30-09-25.json',
            ),
        ]);

        $result = app(RefundMigrationJournalImportService::class)->import();

        $this->assertSame(292, $result['imported']);
        $this->assertSame(0, $result['skipped']);
        $this->assertDatabaseCount('central_wallet_refund_migrations', 292);
    }

    public function test_batch_gate_blocks_without_targets(): void
    {
        config([
            'central_wallet.refund_migration.manifest_path' => base_path(
                'storage/app/private/cw-migration-manifest-final-p30-09-25.json',
            ),
        ]);
        $this->seedJournalRow(1, RefundMigrationLane::Lane1SpokeCutover);
        $blockers = app(RefundMigrationBatchGate::class)->evaluate('OWNER-APPROVAL-TEST');

        $this->assertNotEmpty($blockers);
        $codes = collect($blockers)->pluck('code')->all();
        $this->assertContains('missing_desk_customer_id', $codes);
        $this->assertContains('journal_count_mismatch', $codes);
        $this->assertTrue(
            collect($blockers)->contains(
                fn (array $b): bool => $b['code'] === 'missing_desk_customer_id' && ($b['refund_id'] ?? null) === 1,
            ),
        );
    }

    public function test_batch_gate_blocks_without_owner_approval(): void
    {
        $blockers = app(RefundMigrationBatchGate::class)->evaluate('');
        $this->assertTrue(collect($blockers)->contains(fn (array $b): bool => $b['code'] === 'owner_approval_required'));
    }

    public function test_lane3_provenance_credit_is_idempotent(): void
    {
        [$customer, $refund] = $this->seedLane3PreparedRow(9002, 'REF-TEST-LANE3');
        $migration = CentralWalletRefundMigration::query()->where('refund_id', 9002)->firstOrFail();
        $service = app(RefundProvenanceMigrationService::class);

        $first = $service->execute($migration, 'OWNER-APPROVAL-TEST', (string) Str::uuid(), 'test');
        $migration->refresh();
        $second = $service->execute($migration, 'OWNER-APPROVAL-TEST', (string) Str::uuid(), 'test');

        $this->assertSame(RefundMigrationStatus::Reconciled->value, $first['status']);
        $this->assertTrue($second['idempotent_replay'] ?? false);
        $this->assertSame(1, CentralWalletLedgerEntry::query()->where('entry_type', 'credit')->count());
        $this->assertDatabaseHas('central_wallet_ledger_entries', [
            'central_wallet_id' => $customer->central_wallet_id,
            'source_system' => 'radium-desk',
            'source_reference' => RefundMigrationIdempotencyKey::ledgerSourceReference(9002),
            'business_reference' => 'REF-TEST-LANE3',
            'amount' => '499.00',
        ]);
    }

    public function test_lane1_executes_spoke_cutover_and_credits_central_wallet(): void
    {
        [$customer] = $this->seedLane1PreparedRow(9001, 'REF-TEST-LANE1', 99001);
        $migration = CentralWalletRefundMigration::query()->where('refund_id', 9001)->firstOrFail();
        $executor = app(RefundMigrationLane1Executor::class);

        $result = $executor->execute($migration, 'OWNER-APPROVAL-TEST', (string) Str::uuid(), 'test');

        $this->assertSame(RefundMigrationStatus::Reconciled->value, $result['status']);
        $this->assertCount(1, $this->spoke->retireCalls);
        $this->assertDatabaseHas('central_wallet_ledger_entries', [
            'central_wallet_id' => $customer->central_wallet_id,
            'amount' => '499.00',
            'business_reference' => 'REF-TEST-LANE1',
        ]);
    }

    public function test_user3_lane1_treatment_preserves_exact_499_amount(): void
    {
        $user3Cwid = '50ff2e87-6030-4ae8-b93a-163884db90c5';
        CentralWallet::query()->forceCreate([
            'id' => $user3Cwid,
            'status' => 'active',
        ]);
        $customer = CentralCustomer::query()->create([
            'id' => (string) Str::uuid(),
            'central_wallet_id' => $user3Cwid,
            'status' => 'active',
        ]);

        $this->seedRefund(300, 'REF-2026-000300', '499.00', 'RD3437407');
        CentralWalletRefundMigration::query()->create([
            'id' => (string) Str::uuid(),
            'batch_id' => RefundMigrationManifestLoader::BATCH_ID,
            'refund_id' => 300,
            'refund_reference' => 'REF-2026-000300',
            'amount' => '499.00',
            'source_type' => 'spoke_wallet',
            'source_application' => 'rdservice.in',
            'source_wallet_id' => 2540,
            'source_reference' => RefundMigrationIdempotencyKey::ledgerSourceReference(300),
            'desk_customer_id' => $customer->id,
            'cwid' => $user3Cwid,
            'lane' => RefundMigrationLane::Lane1SpokeCutover,
            'status' => RefundMigrationStatus::Prepared,
            'idempotency_key' => RefundMigrationIdempotencyKey::forRefund(300),
            'owner_approval_ref' => 'OWNER-USER3-TEST',
            'order_number' => 'RD3437407',
            'identity_class' => 'A',
            'metadata' => ['order_resolved_user_id' => '3'],
            'prepared_at' => now(),
        ]);

        $migration = CentralWalletRefundMigration::query()->where('refund_id', 300)->firstOrFail();
        $result = app(RefundMigrationLane1Executor::class)->execute(
            $migration,
            'OWNER-USER3-TEST',
            (string) Str::uuid(),
            'test',
        );

        $this->assertSame('499.00', (string) $migration->fresh()->amount);
        $this->assertSame(RefundMigrationStatus::Reconciled->value, $result['status']);
        $this->assertDatabaseHas('central_wallet_ledger_entries', [
            'central_wallet_id' => $user3Cwid,
            'amount' => '499.00',
            'business_reference' => 'REF-2026-000300',
        ]);
    }

    public function test_rollback_reverses_lane3_credit_idempotently(): void
    {
        [, $refund] = $this->seedLane3PreparedRow(9010, 'REF-TEST-ROLLBACK');
        $migration = CentralWalletRefundMigration::query()->where('refund_id', 9010)->firstOrFail();
        app(RefundProvenanceMigrationService::class)->execute($migration, 'OWNER-ROLLBACK', (string) Str::uuid(), 'test');
        $migration->refresh();

        $rollback = app(RefundMigrationRollbackService::class);
        $first = $rollback->rollback($migration, 'OWNER-ROLLBACK', (string) Str::uuid(), 'test');
        $migration->refresh();
        $second = $rollback->rollback($migration, 'OWNER-ROLLBACK', (string) Str::uuid(), 'test');

        $this->assertSame(RefundMigrationStatus::Reversed->value, $first['status']);
        $this->assertTrue($second['idempotent_replay'] ?? false);
        $this->assertSame(1, CentralWalletLedgerEntry::query()->where('entry_type', 'reversal')->count());
    }

    public function test_assign_owner_resolution_unblocks_ambiguous_row(): void
    {
        $customer = $this->createCustomerWithWallet();
        $this->seedRefund(9020, 'REF-TEST-AMBIG', '499.00', 'RD3506565');
        CentralWalletRefundMigration::query()->create([
            'id' => (string) Str::uuid(),
            'batch_id' => RefundMigrationManifestLoader::BATCH_ID,
            'refund_id' => 9020,
            'refund_reference' => 'REF-TEST-AMBIG',
            'amount' => '499.00',
            'source_type' => 'ambiguous_spoke',
            'lane' => RefundMigrationLane::Lane2AmbiguityResolution,
            'status' => RefundMigrationStatus::Pending,
            'idempotency_key' => RefundMigrationIdempotencyKey::forRefund(9020),
            'source_reference' => RefundMigrationIdempotencyKey::ledgerSourceReference(9020),
            'order_number' => 'RD3506565',
            'identity_class' => 'D',
        ]);

        $migration = app(RefundMigrationTargetAssignmentService::class)->assignOwnerResolution(9020, [
            'resolution_type' => 'ambiguous_spoke_target',
            'source_application' => 'rdservice.in',
            'source_wallet_id' => 88001,
            'source_local_user_id' => '77',
            'desk_customer_id' => $customer->id,
            'cwid' => $customer->central_wallet_id,
            'owner_approval_ref' => 'OWNER-AMBIG-TEST',
            'approved_by' => 'test',
        ]);

        $this->assertSame(RefundMigrationLane::Lane1SpokeCutover, $migration->lane);
        $this->assertSame(RefundMigrationStatus::Prepared, $migration->status);
        $this->assertSame('rdservice.in', $migration->source_application);
        $this->assertSame(88001, $migration->source_wallet_id);
    }

    public function test_dry_run_reports_pending_identity_without_writes(): void
    {
        config([
            'central_wallet.refund_migration.manifest_path' => base_path(
                'storage/app/private/cw-migration-manifest-final-p30-09-25.json',
            ),
        ]);
        $this->seedJournalRow(9003, RefundMigrationLane::Lane3BlockedInsufficientEvidence);
        $report = app(RefundMigrationDryRunService::class)->run();

        $this->assertTrue($report['dry_run']);
        $this->assertGreaterThan(0, $report['pending_identity_count']);
        $this->assertSame(0, CentralWalletLedgerEntry::query()->count());
    }

    public function test_orchestrator_refuses_non_prepared_lane(): void
    {
        $this->seedJournalRow(9030, RefundMigrationLane::Lane2AmbiguityResolution);
        $migration = CentralWalletRefundMigration::query()->where('refund_id', 9030)->firstOrFail();

        $this->expectException(InvalidArgumentException::class);
        app(RefundMigrationOrchestrator::class)->executeSingle($migration, 'OWNER-TEST');
    }

    public function test_cwid_customer_mismatch_is_rejected(): void
    {
        $customer = $this->createCustomerWithWallet();
        $otherWallet = CentralWallet::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'status' => 'active',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cwid_does_not_belong_to_customer');
        app(RefundMigrationTargetAssignmentService::class)->assignDirectTarget(
            9040,
            $customer->id,
            $otherWallet->id,
            'OWNER-TEST',
            'test',
        );
    }

    /**
     * @return array{0: CentralCustomer, 1: RefundRequest}
     */
    private function seedLane3PreparedRow(int $refundId, string $reference): array
    {
        $customer = $this->createCustomerWithWallet();
        $refund = $this->seedRefund($refundId, $reference, '499.00', 'RD3451693');
        CentralWalletRefundMigration::query()->create([
            'id' => (string) Str::uuid(),
            'batch_id' => RefundMigrationManifestLoader::BATCH_ID,
            'refund_id' => $refundId,
            'refund_reference' => $reference,
            'amount' => '499.00',
            'source_type' => 'refund_provenance',
            'source_reference' => RefundMigrationIdempotencyKey::ledgerSourceReference($refundId),
            'desk_customer_id' => $customer->id,
            'cwid' => $customer->central_wallet_id,
            'lane' => RefundMigrationLane::Lane3RefundProvenance,
            'status' => RefundMigrationStatus::Prepared,
            'idempotency_key' => RefundMigrationIdempotencyKey::forRefund($refundId),
            'owner_approval_ref' => 'OWNER-APPROVAL-TEST',
            'order_number' => 'RD3451693',
            'identity_class' => 'C',
            'metadata' => ['order_resolved_user_id' => '506514'],
            'prepared_at' => now(),
        ]);

        return [$customer, $refund];
    }

    /**
     * @return array{0: CentralCustomer}
     */
    private function seedLane1PreparedRow(int $refundId, string $reference, int $walletId): array
    {
        $customer = $this->createCustomerWithWallet();
        $this->seedRefund($refundId, $reference, '499.00', 'RD3437407');
        CentralWalletRefundMigration::query()->create([
            'id' => (string) Str::uuid(),
            'batch_id' => RefundMigrationManifestLoader::BATCH_ID,
            'refund_id' => $refundId,
            'refund_reference' => $reference,
            'amount' => '499.00',
            'source_type' => 'spoke_wallet',
            'source_application' => 'rdservice.in',
            'source_wallet_id' => $walletId,
            'source_reference' => RefundMigrationIdempotencyKey::ledgerSourceReference($refundId),
            'desk_customer_id' => $customer->id,
            'cwid' => $customer->central_wallet_id,
            'lane' => RefundMigrationLane::Lane1SpokeCutover,
            'status' => RefundMigrationStatus::Prepared,
            'idempotency_key' => RefundMigrationIdempotencyKey::forRefund($refundId),
            'owner_approval_ref' => 'OWNER-APPROVAL-TEST',
            'order_number' => 'RD3437407',
            'identity_class' => 'B',
            'metadata' => ['order_resolved_user_id' => '3'],
            'prepared_at' => now(),
        ]);

        return [$customer];
    }

    private function seedJournalRow(int $refundId, RefundMigrationLane $lane): void
    {
        $reference = $refundId === 1 ? 'REF-2026-000001' : 'REF-TEST-'.$refundId;
        $amount = $refundId === 1 ? '717.00' : '499.00';
        $this->seedRefund($refundId, $reference, $amount, 'RD3449894');
        CentralWalletRefundMigration::query()->create([
            'id' => (string) Str::uuid(),
            'batch_id' => RefundMigrationManifestLoader::BATCH_ID,
            'refund_id' => $refundId,
            'refund_reference' => $reference,
            'amount' => $amount,
            'source_type' => 'none',
            'source_reference' => RefundMigrationIdempotencyKey::ledgerSourceReference($refundId),
            'lane' => $lane,
            'status' => RefundMigrationStatus::Pending,
            'idempotency_key' => RefundMigrationIdempotencyKey::forRefund($refundId),
            'identity_class' => 'E',
        ]);
    }

    private function createCustomerWithWallet(): CentralCustomer
    {
        $wallet = CentralWallet::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'status' => 'active',
        ]);

        return CentralCustomer::query()->create([
            'id' => (string) Str::uuid(),
            'central_wallet_id' => $wallet->id,
            'status' => 'active',
        ]);
    }

    private function seedRefund(int $id, string $reference, string $amount, string $orderNumber): RefundRequest
    {
        $user = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => $orderNumber,
            'serial_number' => 'SN-'.$orderNumber,
            'product_name' => 'Test',
            'device_model' => 'Model',
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        return RefundRequest::query()->create([
            'id' => $id,
            'order_id' => $order->id,
            'reference_no' => $reference,
            'amount' => $amount,
            'reason' => 'Test refund migration',
            'status' => RefundStatus::Closed,
            'approved_refund_method' => ApprovedRefundMethod::Wallet,
            'refund_amount' => $amount,
            'requested_by' => $user->id,
        ]);
    }
}
