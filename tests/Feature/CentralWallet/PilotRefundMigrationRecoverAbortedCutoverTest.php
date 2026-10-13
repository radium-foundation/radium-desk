<?php

namespace Tests\Feature\CentralWallet;

use App\CentralWallet\Application\Contracts\WalletMigrationSpokeClient;
use App\CentralWallet\Application\PilotRefundMigrationRecoverAbortedCutoverService;
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
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\Support\FakeWalletMigrationSpokeClient;
use Tests\TestCase;

class PilotRefundMigrationRecoverAbortedCutoverTest extends TestCase
{
    use RefreshDatabase;

    private const PARENT_OP = '7392cb9c-b869-437c-b43d-9aaf6579b195';

    private const OWNER = 'OWNER-REF-67395-PILOT-TEST';

    private const CONFIRM = 'recover-aborted-cutover-test-confirm';

    private string $manifestPath;

    private FakeWalletMigrationSpokeClient $spoke;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->manifestPath = base_path('tests/fixtures/cw-pilot-refund-ref-67395-manifest.json');
        $this->spoke = new FakeWalletMigrationSpokeClient;
        $this->app->instance(WalletMigrationSpokeClient::class, $this->spoke);

        config([
            'central_wallet.enabled' => true,
            'central_wallet.pilot_refund_migration.allowed_refund_ids' => '390',
            'central_wallet.pilot_refund_migration.required_owner_approval_ref' => self::OWNER,
            'central_wallet.pilot_refund_migration.recover_aborted_cutover_confirm_token' => self::CONFIRM,
            'central_wallet.pilot_refund_migration.manifest_path' => $this->manifestPath,
            'central_wallet.pilot_refund_migration.manifest_rows_sha256' => '052076aa8f61071e68d46b204f6bdee9c560a98a6fe99841c77f45b6df1a5305',
        ]);
    }

    public function test_recovers_failed_spoke_lock_to_prepared_with_new_attempt_key(): void
    {
        $this->seedPilot390Failed();
        $parent = $this->seedAbortedParentBalanceMigration();

        $result = $this->service()->recover(390, self::OWNER, (string) Str::uuid(), 'test', $this->manifestPath);

        $this->assertSame(RefundMigrationStatus::Prepared->value, $result['status']);
        $this->assertSame(self::PARENT_OP, $result['parent_balance_operation_id']);
        $this->assertNotSame(self::PARENT_OP, $result['attempt_operation_id']);
        $this->assertFalse($result['idempotent_replay']);
        $this->assertNotSame(
            RefundMigrationIdempotencyKey::forRefund(390),
            $result['cutover_idempotency_key'],
        );

        $migration = CentralWalletRefundMigration::query()->where('refund_id', 390)->firstOrFail();
        $this->assertNull($migration->error_code);
        $attempt = RefundMigrationBalanceAttempt::fromMigration($migration);
        $this->assertNotNull($attempt);
        $this->assertSame(1, $attempt['source_wallet_attempt']);
        $this->assertSame(
            PilotRefundMigrationRecoverAbortedCutoverService::RECOVERY_REASON,
            $attempt['reason'],
        );

        $parent->refresh();
        $this->assertSame(BalanceMigrationStatus::Aborted, $parent->status);
        $this->assertSame(
            RefundMigrationIdempotencyKey::forRefund(390),
            $parent->idempotency_key,
        );

        $this->assertDatabaseHas('central_wallet_audit_events', [
            'event_type' => 'refund_migration.recovered_aborted_cutover',
        ]);
        $this->assertSame(0, CentralWalletLedgerEntry::query()->count());
    }

    public function test_repeated_recovery_is_idempotent(): void
    {
        $this->seedPilot390Failed();
        $this->seedAbortedParentBalanceMigration();

        $first = $this->service()->recover(390, self::OWNER, (string) Str::uuid(), 'test', $this->manifestPath);
        $second = $this->service()->recover(390, self::OWNER, (string) Str::uuid(), 'test', $this->manifestPath);

        $this->assertTrue($second['idempotent_replay']);
        $this->assertSame($first['attempt_operation_id'], $second['attempt_operation_id']);
        $this->assertSame(1, CentralWalletBalanceMigration::query()->count());
        $this->assertSame(
            1,
            CentralWalletAuditEvent::query()->where('event_type', 'refund_migration.recovered_aborted_cutover')->count(),
        );
    }

    public function test_wrong_confirm_token_rejected_via_command(): void
    {
        $this->seedPilot390Failed();
        $this->seedAbortedParentBalanceMigration();

        $this->artisan('central-wallet:pilot-refund-migration-recover-aborted-cutover', [
            '--refund-id' => 390,
            '--owner-recovery-ref' => self::OWNER,
            '--manifest' => $this->manifestPath,
            '--confirm' => 'wrong-token',
        ])->assertFailed();
    }

    public function test_wrong_refund_id_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('pilot_refund_id_not_allowlisted');

        $this->service()->recover(387, self::OWNER, (string) Str::uuid(), 'test', $this->manifestPath);
    }

    public function test_reconciliation_required_status_rejected(): void
    {
        $this->seedPilot390Failed();
        CentralWalletRefundMigration::query()->where('refund_id', 390)->update([
            'status' => RefundMigrationStatus::ReconciliationRequired,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('refund_migration_not_aborted_cutover_candidate');

        $this->service()->recover(390, self::OWNER, (string) Str::uuid(), 'test', $this->manifestPath);
    }

    public function test_journal_with_linked_balance_operation_rejected(): void
    {
        $this->seedPilot390Failed();
        $this->seedAbortedParentBalanceMigration();

        CentralWalletRefundMigration::query()->where('refund_id', 390)->update([
            'balance_migration_operation_id' => self::PARENT_OP,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('journal_balance_operation_already_set');

        $this->service()->recover(390, self::OWNER, (string) Str::uuid(), 'test', $this->manifestPath);
    }

    public function test_missing_aborted_parent_rejected(): void
    {
        $this->seedPilot390Failed();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('aborted_parent_balance_operation_not_found');

        $this->service()->recover(390, self::OWNER, (string) Str::uuid(), 'test', $this->manifestPath);
    }

    public function test_destination_ledger_credit_rejected(): void
    {
        $this->seedPilot390Failed();
        $this->seedAbortedParentBalanceMigration();

        CentralWalletLedgerEntry::query()->create([
            'central_wallet_id' => 'd1852026-d8c2-4af4-818c-d3151549f614',
            'entry_type' => 'credit',
            'amount' => '597.00',
            'currency' => 'INR',
            'status' => 'posted',
            'source_system' => 'radium-desk',
            'source_reference' => 'manual-test',
            'correlation_id' => (string) Str::uuid(),
            'business_reference' => 'REF-67395',
            'posted_at' => now(),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('duplicate_ledger_credit');

        $this->service()->recover(390, self::OWNER, (string) Str::uuid(), 'test', $this->manifestPath);
    }

    public function test_active_spoke_lock_rejected(): void
    {
        $this->seedPilot390Failed();
        $this->seedAbortedParentBalanceMigration();
        $this->spoke->statusLockStatus = 'active';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('spoke_migration_lock_active');

        $this->service()->recover(390, self::OWNER, (string) Str::uuid(), 'test', $this->manifestPath);
    }

    public function test_amount_mismatch_on_journal_rejected(): void
    {
        $this->seedPilot390Failed();
        $this->seedAbortedParentBalanceMigration();

        CentralWalletRefundMigration::query()->where('refund_id', 390)->update([
            'amount' => '1.00',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('amount_mismatch');

        $this->service()->recover(390, self::OWNER, (string) Str::uuid(), 'test', $this->manifestPath);
    }

    public function test_failed_recovery_leaves_no_partial_prepared_state(): void
    {
        $this->seedPilot390Failed();

        try {
            $this->service()->recover(390, self::OWNER, (string) Str::uuid(), 'test', $this->manifestPath);
            $this->fail('Expected missing parent');
        } catch (InvalidArgumentException) {
        }

        $migration = CentralWalletRefundMigration::query()->where('refund_id', 390)->firstOrFail();
        $this->assertSame(RefundMigrationStatus::Failed, $migration->status);
        $this->assertSame('spoke_lock_failed', $migration->error_code);
        $this->assertNull(RefundMigrationBalanceAttempt::fromMigration($migration));
    }

    public function test_spoke_status_http_400_fails_closed_without_journal_mutation(): void
    {
        $this->assertSpokeHttpErrorFailsClosedWithoutJournalMutation(400);
    }

    public function test_spoke_status_http_401_fails_closed_without_journal_mutation(): void
    {
        $this->assertSpokeHttpErrorFailsClosedWithoutJournalMutation(401);
    }

    public function test_spoke_status_http_403_fails_closed_without_journal_mutation(): void
    {
        $this->assertSpokeHttpErrorFailsClosedWithoutJournalMutation(403);
    }

    public function test_spoke_status_http_404_fails_closed_without_journal_mutation(): void
    {
        $this->assertSpokeHttpErrorFailsClosedWithoutJournalMutation(404);
    }

    public function test_spoke_status_http_503_fails_closed_without_journal_mutation(): void
    {
        $this->assertSpokeHttpErrorFailsClosedWithoutJournalMutation(503);
    }

    private function assertSpokeHttpErrorFailsClosedWithoutJournalMutation(int $httpStatus): void
    {
        $this->seedPilot390Failed();
        $this->seedAbortedParentBalanceMigration();
        $this->spoke->statusHttpCode = $httpStatus;

        try {
            $this->service()->recover(390, self::OWNER, (string) Str::uuid(), 'test', $this->manifestPath);
            $this->fail('Expected spoke_status_unavailable');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('spoke_status_unavailable', $exception->getMessage());
        }

        $migration = CentralWalletRefundMigration::query()->where('refund_id', 390)->firstOrFail();
        $this->assertSame(RefundMigrationStatus::Failed, $migration->status);
        $this->assertNull(RefundMigrationBalanceAttempt::fromMigration($migration));
        $this->assertSame(0, CentralWalletAuditEvent::query()->count());
    }

    public function test_spoke_status_missing_lock_status_rejected(): void
    {
        $this->seedPilot390Failed();
        $this->seedAbortedParentBalanceMigration();
        $this->spoke->statusOmitLockStatus = true;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('spoke_lock_status_missing');

        $this->service()->recover(390, self::OWNER, (string) Str::uuid(), 'test', $this->manifestPath);
    }

    public function test_spoke_status_retired_allows_recovery(): void
    {
        $this->seedPilot390Failed();
        $this->seedAbortedParentBalanceMigration();
        $this->spoke->statusLockStatus = 'retired';

        $result = $this->service()->recover(390, self::OWNER, (string) Str::uuid(), 'test', $this->manifestPath);

        $this->assertSame(RefundMigrationStatus::Prepared->value, $result['status']);
    }

    public function test_replay_rejects_when_spoke_lock_becomes_active(): void
    {
        $this->seedPilot390Failed();
        $this->seedAbortedParentBalanceMigration();

        $first = $this->service()->recover(390, self::OWNER, (string) Str::uuid(), 'test', $this->manifestPath);
        $this->spoke->statusLockStatus = 'active';

        try {
            $this->service()->recover(390, self::OWNER, (string) Str::uuid(), 'test', $this->manifestPath);
            $this->fail('Expected spoke_migration_lock_active on replay');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('spoke_migration_lock_active', $exception->getMessage());
        }

        $migration = CentralWalletRefundMigration::query()->where('refund_id', 390)->firstOrFail();
        $this->assertSame(RefundMigrationStatus::Prepared, $migration->status);
        $attempt = RefundMigrationBalanceAttempt::fromMigration($migration);
        $this->assertNotNull($attempt);
        $this->assertSame($first['attempt_operation_id'], $attempt['attempt_operation_id']);
        $this->assertSame(
            1,
            CentralWalletAuditEvent::query()->where('event_type', 'refund_migration.recovered_aborted_cutover')->count(),
        );
        $this->assertSame(0, CentralWalletLedgerEntry::query()->count());
    }

    public function test_replay_rejects_when_spoke_status_becomes_unavailable(): void
    {
        $this->seedPilot390Failed();
        $this->seedAbortedParentBalanceMigration();

        $first = $this->service()->recover(390, self::OWNER, (string) Str::uuid(), 'test', $this->manifestPath);
        $this->spoke->statusHttpCode = 503;

        try {
            $this->service()->recover(390, self::OWNER, (string) Str::uuid(), 'test', $this->manifestPath);
            $this->fail('Expected spoke_status_unavailable on replay');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('spoke_status_unavailable', $exception->getMessage());
        }

        $migration = CentralWalletRefundMigration::query()->where('refund_id', 390)->firstOrFail();
        $this->assertSame(RefundMigrationStatus::Prepared, $migration->status);
        $this->assertSame($first['attempt_operation_id'], RefundMigrationBalanceAttempt::fromMigration($migration)['attempt_operation_id']);
    }

    public function test_replay_rejects_when_live_refund_data_no_longer_matches(): void
    {
        $this->seedPilot390Failed();
        $this->seedAbortedParentBalanceMigration();

        $first = $this->service()->recover(390, self::OWNER, (string) Str::uuid(), 'test', $this->manifestPath);

        RefundRequest::query()->where('id', 390)->update(['refund_amount' => '1.00']);

        try {
            $this->service()->recover(390, self::OWNER, (string) Str::uuid(), 'test', $this->manifestPath);
            $this->fail('Expected live_refund_amount_mismatch on replay');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('live_refund_amount_mismatch', $exception->getMessage());
        }

        $migration = CentralWalletRefundMigration::query()->where('refund_id', 390)->firstOrFail();
        $this->assertSame(RefundMigrationStatus::Prepared, $migration->status);
        $this->assertSame($first['attempt_operation_id'], RefundMigrationBalanceAttempt::fromMigration($migration)['attempt_operation_id']);
        $this->assertSame(1, CentralWalletAuditEvent::query()->where('event_type', 'refund_migration.recovered_aborted_cutover')->count());
    }

    private function service(): PilotRefundMigrationRecoverAbortedCutoverService
    {
        return app(PilotRefundMigrationRecoverAbortedCutoverService::class);
    }

    private function seedPilot390Failed(): void
    {
        $cwid = 'd1852026-d8c2-4af4-818c-d3151549f614';
        CentralWallet::query()->forceCreate(['id' => $cwid, 'status' => 'active']);
        CentralCustomer::query()->create([
            'id' => 'c51247d0-bb8a-4924-8389-8503ba32f5bc',
            'central_wallet_id' => $cwid,
            'status' => 'active',
        ]);

        $user = User::factory()->create();
        DB::table('orders')->insert([
            'id' => 62928,
            'order_id' => 'RD17641',
            'customer_id' => 'c51247d0-bb8a-4924-8389-8503ba32f5bc',
            'serial_number' => 'SN',
            'product_name' => 'Device',
            'device_model' => 'X',
            'status' => 'active',
            'payment_amount' => '597.00',
            'created_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        RefundRequest::query()->forceCreate([
            'id' => 390,
            'order_id' => 62928,
            'reference_no' => 'REF-67395',
            'amount' => '597.00',
            'refund_amount' => '597.00',
            'reason' => 'Pilot fixture.',
            'approved_refund_method' => ApprovedRefundMethod::Wallet,
            'status' => RefundStatus::Closed,
            'execution_transaction_id' => '2667',
            'requested_by' => $user->id,
            'communication_channels' => [],
        ]);

        CentralWalletRefundMigration::query()->create([
            'id' => (string) Str::uuid(),
            'batch_id' => 'desk-refund-wallet-migration-pilot-ref-67395',
            'refund_id' => 390,
            'refund_reference' => 'REF-67395',
            'amount' => '597.00',
            'source_type' => 'spoke_wallet',
            'source_application' => 'rdservice.in',
            'source_wallet_id' => 2667,
            'source_reference' => RefundMigrationIdempotencyKey::ledgerSourceReference(390),
            'desk_customer_id' => 'c51247d0-bb8a-4924-8389-8503ba32f5bc',
            'cwid' => $cwid,
            'lane' => RefundMigrationLane::Lane1SpokeCutover,
            'status' => RefundMigrationStatus::Failed,
            'error_code' => 'spoke_lock_failed',
            'error_message' => '{"error":"spoke_lock_failed"}',
            'idempotency_key' => RefundMigrationIdempotencyKey::forRefund(390),
            'order_number' => 'RD17641',
            'metadata' => [
                'order_resolved_user_id' => '563447',
                'execution_transaction_id' => '2667',
            ],
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
            'idempotency_key' => RefundMigrationIdempotencyKey::forRefund(390),
            'status' => BalanceMigrationStatus::Aborted,
            'failure_code' => 'spoke_lock_failed',
            'source_site_code' => 'rdservice.in',
            'source_local_user_id' => '563447',
            'source_users_wallet_id' => 2667,
            'source_wallet_attempt' => 0,
            'source_order_reference' => 'RD17641',
            'source_business_reference' => 'REF-67395',
            'source_amount' => '597.00',
            'source_currency' => 'INR',
            'destination_central_wallet_id' => 'd1852026-d8c2-4af4-818c-d3151549f614',
            'migration_batch_id' => 'desk-refund-wallet-migration-pilot-ref-67395',
            'correlation_id' => (string) Str::uuid(),
        ], $overrides);
    }
}
