<?php

namespace Tests\Feature\CentralWallet;

use App\CentralWallet\Application\Contracts\WalletMigrationSpokeClient;
use App\CentralWallet\Application\PilotRefundMigrationOrchestrator;
use App\CentralWallet\Application\PilotRefundMigrationReprepareService;
use App\CentralWallet\Application\RefundMigrationBalanceAttempt;
use App\CentralWallet\Domain\Enums\BalanceMigrationStatus;
use App\CentralWallet\Domain\Enums\RefundMigrationLane;
use App\CentralWallet\Domain\Enums\RefundMigrationStatus;
use App\CentralWallet\Domain\RefundMigrationIdempotencyKey;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralWallet;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletAuditEvent;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletBalanceMigration;
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

class PilotRefundMigrationReprepareTest extends TestCase
{
    use RefreshDatabase;

    private const PARENT_OP = 'bf9f4dda-2dd3-48fc-9e0c-eb9127a8c941';

    private const OWNER = 'OWNER-PILOT-TEST';

    private const CONFIRM = 'reprepare-test-confirm-token';

    private FakeWalletMigrationSpokeClient $spoke;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->spoke = new FakeWalletMigrationSpokeClient;
        $this->app->instance(WalletMigrationSpokeClient::class, $this->spoke);

        config([
            'central_wallet.enabled' => true,
            'central_wallet.balance_migration.execution_enabled' => true,
            'central_wallet.refund_migration.execution_enabled' => true,
            'central_wallet.pilot_refund_migration.allowed_refund_ids' => '387',
            'central_wallet.pilot_refund_migration.required_owner_approval_ref' => self::OWNER,
            'central_wallet.pilot_refund_migration.reprepare_confirm_token' => self::CONFIRM,
        ]);
    }

    public function test_reprepare_moves_reconciliation_required_to_prepared_with_new_attempt(): void
    {
        $this->seedPilot387();
        $parent = $this->seedAbortedParentBalanceMigration();

        $result = $this->service()->reprepare(387, self::OWNER, (string) Str::uuid(), 'test');

        $this->assertSame(RefundMigrationStatus::Prepared->value, $result['status']);
        $this->assertSame(self::PARENT_OP, $result['parent_balance_operation_id']);
        $this->assertNotSame(self::PARENT_OP, $result['attempt_operation_id']);
        $this->assertFalse($result['idempotent_replay']);

        $migration = CentralWalletRefundMigration::query()->where('refund_id', 387)->firstOrFail();
        $this->assertNull($migration->balance_migration_operation_id);
        $attempt = RefundMigrationBalanceAttempt::fromMigration($migration);
        $this->assertNotNull($attempt);
        $this->assertSame(1, $attempt['source_wallet_attempt']);

        $parent->refresh();
        $this->assertSame(BalanceMigrationStatus::Aborted, $parent->status);
        $this->assertSame(
            RefundMigrationIdempotencyKey::forRefund(387),
            $parent->idempotency_key,
        );

        $this->assertDatabaseHas('central_wallet_audit_events', [
            'event_type' => 'refund_migration.reprepared',
        ]);
        $this->assertSame(0, CentralWalletLedgerEntry::query()->count());
        $this->assertSame(0, count($this->spoke->lockCalls));
    }

    public function test_repeated_reprepare_is_idempotent(): void
    {
        $this->seedPilot387();
        $this->seedAbortedParentBalanceMigration();

        $first = $this->service()->reprepare(387, self::OWNER, (string) Str::uuid(), 'test');
        $second = $this->service()->reprepare(387, self::OWNER, (string) Str::uuid(), 'test');

        $this->assertTrue($second['idempotent_replay']);
        $this->assertSame($first['attempt_operation_id'], $second['attempt_operation_id']);
        $this->assertSame(1, CentralWalletBalanceMigration::query()->count());
        $this->assertSame(
            1,
            CentralWalletAuditEvent::query()->where('event_type', 'refund_migration.reprepared')->count(),
        );
    }

    public function test_wrong_confirm_token_rejected_via_command(): void
    {
        $this->seedPilot387();
        $this->seedAbortedParentBalanceMigration();

        $this->artisan('central-wallet:pilot-refund-migration-reprepare', [
            '--refund-id' => 387,
            '--owner-recovery-ref' => self::OWNER,
            '--confirm' => 'wrong-token',
        ])->assertFailed();
    }

    public function test_wrong_refund_id_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('pilot_refund_id_not_allowlisted');

        $this->service()->reprepare(999, self::OWNER, (string) Str::uuid(), 'test');
    }

    public function test_wrong_status_rejected(): void
    {
        $this->seedPilot387();
        CentralWalletRefundMigration::query()->where('refund_id', 387)->update([
            'status' => RefundMigrationStatus::Failed,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('refund_migration_not_reprepare_candidate');

        $this->service()->reprepare(387, self::OWNER, (string) Str::uuid(), 'test');
    }

    public function test_destination_ledger_present_rejected(): void
    {
        $this->seedPilot387();
        $this->seedAbortedParentBalanceMigration();

        $entry = CentralWalletLedgerEntry::query()->create([
            'central_wallet_id' => '8946cd5e-208d-420f-b922-f5727bc47a87',
            'entry_type' => 'credit',
            'amount' => '499.00',
            'currency' => 'INR',
            'status' => 'posted',
            'source_system' => 'radium-desk',
            'source_reference' => 'manual-test',
            'correlation_id' => (string) Str::uuid(),
            'business_reference' => 'REF-67392',
            'posted_at' => now(),
        ]);

        CentralWalletRefundMigration::query()->where('refund_id', 387)->update([
            'destination_ledger_entry_id' => $entry->id,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('destination_ledger_already_present');

        $this->service()->reprepare(387, self::OWNER, (string) Str::uuid(), 'test');
    }

    public function test_source_retirement_present_rejected(): void
    {
        $this->seedPilot387();
        $this->seedAbortedParentBalanceMigration();

        CentralWalletRefundMigration::query()->where('refund_id', 387)->update([
            'source_debit_reference' => 'retired:2663',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('source_retirement_already_present');

        $this->service()->reprepare(387, self::OWNER, (string) Str::uuid(), 'test');
    }

    public function test_parent_balance_operation_must_be_aborted(): void
    {
        $this->seedPilot387();
        CentralWalletBalanceMigration::query()->create($this->parentBalanceAttributes([
            'status' => BalanceMigrationStatus::Reconciled,
            'destination_ledger_entry_id' => 1,
            'source_retirement_reference' => '2663',
        ]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('parent_balance_operation_not_aborted');

        $this->service()->reprepare(387, self::OWNER, (string) Str::uuid(), 'test');
    }

    public function test_execute_cannot_bypass_prepared_from_reconciliation_required(): void
    {
        $this->seedPilot387();
        $this->seedAbortedParentBalanceMigration();

        $migration = CentralWalletRefundMigration::query()->where('refund_id', 387)->firstOrFail();

        try {
            app(PilotRefundMigrationOrchestrator::class)->executeSingle(
                $migration,
                self::OWNER,
                base_path('tests/fixtures/cw-pilot-refund-ref-67392-manifest.json'),
            );
            $this->fail('Expected execute to be blocked');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('status_not_prepared', $exception->getMessage());
        }
    }

    public function test_reprepare_does_not_mutate_refund_financial_fields(): void
    {
        $this->seedPilot387();
        $this->seedAbortedParentBalanceMigration();

        $before = RefundRequest::query()->find(387);

        $this->service()->reprepare(387, self::OWNER, (string) Str::uuid(), 'test');

        $after = RefundRequest::query()->find(387);
        $this->assertSame($before->status?->value, $after->status?->value);
        $this->assertSame((string) $before->refund_amount, (string) $after->refund_amount);
        $this->assertSame((string) $before->execution_transaction_id, (string) $after->execution_transaction_id);
    }

    public function test_future_cutover_uses_attempt_identity_not_parent(): void
    {
        $this->seedPilot387();
        $this->seedAbortedParentBalanceMigration();

        $this->service()->reprepare(387, self::OWNER, (string) Str::uuid(), 'test');
        $migration = CentralWalletRefundMigration::query()->where('refund_id', 387)->firstOrFail();
        $overrides = RefundMigrationBalanceAttempt::cutoverOverrides($migration);

        $this->assertNotSame(RefundMigrationIdempotencyKey::forRefund(387), $overrides['idempotency_key']);
        $this->assertSame(1, $overrides['source_wallet_attempt']);
        $this->assertNotSame(self::PARENT_OP, $overrides['migration_operation_id']);
    }

    private function service(): PilotRefundMigrationReprepareService
    {
        return app(PilotRefundMigrationReprepareService::class);
    }

    private function seedPilot387(): void
    {
        $cwid = '8946cd5e-208d-420f-b922-f5727bc47a87';
        CentralWallet::query()->forceCreate(['id' => $cwid, 'status' => 'active']);
        CentralCustomer::query()->create([
            'id' => '90f0b408-f874-40e1-927b-6ce41cee0aa9',
            'central_wallet_id' => $cwid,
            'status' => 'active',
        ]);

        $orderId = 62567;
        DB::table('orders')->insert([
            'id' => $orderId,
            'order_id' => 'RD16854',
            'customer_id' => '90f0b408-f874-40e1-927b-6ce41cee0aa9',
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
            'reason' => 'Pilot fixture.',
            'approved_refund_method' => ApprovedRefundMethod::Wallet,
            'status' => RefundStatus::Closed,
            'execution_transaction_id' => '2663',
            'requested_by' => $user->id,
            'communication_channels' => [],
        ]);

        CentralWalletRefundMigration::query()->create([
            'id' => (string) Str::uuid(),
            'batch_id' => 'pilot',
            'refund_id' => 387,
            'refund_reference' => 'REF-67392',
            'amount' => '499.00',
            'source_type' => 'spoke_wallet',
            'source_application' => 'rdservice.in',
            'source_wallet_id' => 2663,
            'source_reference' => RefundMigrationIdempotencyKey::ledgerSourceReference(387),
            'desk_customer_id' => '90f0b408-f874-40e1-927b-6ce41cee0aa9',
            'cwid' => $cwid,
            'lane' => RefundMigrationLane::Lane1SpokeCutover,
            'status' => RefundMigrationStatus::ReconciliationRequired,
            'idempotency_key' => RefundMigrationIdempotencyKey::forRefund(387),
            'balance_migration_operation_id' => self::PARENT_OP,
            'order_number' => 'RD16854',
            'metadata' => ['false_reconcile_repair' => ['repaired_at' => now()->toIso8601String()]],
        ]);
    }

    private function seedAbortedParentBalanceMigration(): CentralWalletBalanceMigration
    {
        return CentralWalletBalanceMigration::query()->create($this->parentBalanceAttributes());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function parentBalanceAttributes(array $overrides = []): array
    {
        return array_merge([
            'migration_operation_id' => self::PARENT_OP,
            'idempotency_key' => RefundMigrationIdempotencyKey::forRefund(387),
            'status' => BalanceMigrationStatus::Aborted,
            'failure_code' => 'spoke_lock_failed',
            'source_site_code' => 'rdservice.in',
            'source_local_user_id' => '562976',
            'source_users_wallet_id' => 2663,
            'source_wallet_attempt' => 0,
            'source_order_reference' => 'RD16854',
            'source_business_reference' => 'REF-67392',
            'source_amount' => '499.00',
            'source_currency' => 'INR',
            'destination_central_wallet_id' => '8946cd5e-208d-420f-b922-f5727bc47a87',
            'migration_batch_id' => 'pilot',
            'correlation_id' => (string) Str::uuid(),
        ], $overrides);
    }
}
