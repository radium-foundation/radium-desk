<?php

namespace Tests\Feature\CentralWallet;

use App\CentralWallet\Application\HistoricalWalletRefundReconciliationCohortAnalyzer;
use App\CentralWallet\Domain\Enums\RefundMigrationLane;
use App\CentralWallet\Domain\Enums\RefundMigrationStatus;
use App\CentralWallet\Domain\RefundMigrationIdempotencyKey;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralWallet;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletRefundMigration;
use App\Enums\ApprovedRefundMethod;
use App\Enums\RefundStatus;
use App\Models\RefundRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HistoricalWalletRefundReconciliationCohortAnalyzerTest extends TestCase
{
    use RefreshDatabase;

    public function test_policy_violation_with_bound_customer_is_eligible(): void
    {
        $this->seedViolationFixture();

        $report = app(HistoricalWalletRefundReconciliationCohortAnalyzer::class)->analyze(50);

        $this->assertSame(1, $report['summary']['eligible']);
        $this->assertSame(0, $report['summary']['blocked']);
        $this->assertSame('REF-COHORT-1', $report['eligible'][0]['reference_no']);
    }

    public function test_policy_violation_without_customer_uuid_is_blocked(): void
    {
        $user = User::factory()->create();
        $orderId = 70001;
        DB::table('orders')->insert([
            'id' => $orderId,
            'order_id' => 'RD70001',
            'customer_id' => 'not-a-uuid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        RefundRequest::query()->forceCreate([
            'id' => 70001,
            'order_id' => $orderId,
            'reference_no' => 'REF-COHORT-BLOCKED',
            'amount' => '10.00',
            'refund_amount' => '10.00',
            'reason' => 'blocked cohort',
            'approved_refund_method' => ApprovedRefundMethod::Wallet,
            'status' => RefundStatus::Closed,
            'execution_transaction_id' => '99',
            'requested_by' => $user->id,
            'communication_channels' => [],
        ]);

        $report = app(HistoricalWalletRefundReconciliationCohortAnalyzer::class)->analyze(50);

        $this->assertSame(0, $report['summary']['eligible']);
        $this->assertSame(1, $report['summary']['blocked']);
        $this->assertSame('order_customer_unbound', $report['blocked'][0]['blockers'][0]['code']);
    }

    public function test_prepared_migration_journal_is_not_execution_ready(): void
    {
        $this->seedViolationFixture();
        $this->attachMigrationJournal(70002, RefundMigrationStatus::Prepared);

        $report = app(HistoricalWalletRefundReconciliationCohortAnalyzer::class)->analyze(50);

        $this->assertSame(0, $report['summary']['eligible']);
        $this->assertSame(1, $report['summary']['blocked']);
        $this->assertSame('migration_journal_unresolved', $report['blocked'][0]['blockers'][0]['code']);
    }

    public function test_compensating_migration_journal_is_not_execution_ready(): void
    {
        $this->seedViolationFixture();
        $this->attachMigrationJournal(70002, RefundMigrationStatus::Compensating);

        $report = app(HistoricalWalletRefundReconciliationCohortAnalyzer::class)->analyze(50);

        $this->assertSame(0, $report['summary']['eligible']);
        $this->assertContains('migration_journal_unresolved', array_column($report['blocked'][0]['blockers'], 'code'));
    }

    public function test_reconciliation_required_migration_journal_is_not_execution_ready(): void
    {
        $this->seedViolationFixture();
        $this->attachMigrationJournal(70002, RefundMigrationStatus::ReconciliationRequired);

        $report = app(HistoricalWalletRefundReconciliationCohortAnalyzer::class)->analyze(50);

        $this->assertSame(0, $report['summary']['eligible']);
        $this->assertContains('migration_journal_unresolved', array_column($report['blocked'][0]['blockers'], 'code'));
    }

    public function test_failed_migration_journal_remains_eligible_for_reprepare(): void
    {
        $this->seedViolationFixture();
        $this->attachMigrationJournal(70002, RefundMigrationStatus::Failed);

        $report = app(HistoricalWalletRefundReconciliationCohortAnalyzer::class)->analyze(50);

        $this->assertSame(1, $report['summary']['eligible']);
        $this->assertSame('REF-COHORT-1', $report['eligible'][0]['reference_no']);
    }

    private function attachMigrationJournal(int $refundId, RefundMigrationStatus $status): void
    {
        CentralWalletRefundMigration::query()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'batch_id' => 'cohort-test',
            'refund_id' => $refundId,
            'refund_reference' => 'REF-COHORT-1',
            'amount' => '25.00',
            'source_type' => 'spoke_wallet',
            'lane' => RefundMigrationLane::Lane1SpokeCutover,
            'status' => $status,
            'idempotency_key' => 'cohort-'.$refundId,
            'source_reference' => RefundMigrationIdempotencyKey::ledgerSourceReference($refundId),
        ]);
    }

    private function seedViolationFixture(): void
    {
        $cwid = (string) \Illuminate\Support\Str::uuid();
        CentralWallet::query()->create(['id' => $cwid, 'status' => 'active']);
        $customerId = (string) \Illuminate\Support\Str::uuid();
        CentralCustomer::query()->create([
            'id' => $customerId,
            'central_wallet_id' => $cwid,
            'status' => 'active',
        ]);

        $orderId = 70002;
        DB::table('orders')->insert([
            'id' => $orderId,
            'order_id' => 'RD70002',
            'customer_id' => $customerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::factory()->create();
        RefundRequest::query()->forceCreate([
            'id' => 70002,
            'order_id' => $orderId,
            'reference_no' => 'REF-COHORT-1',
            'amount' => '25.00',
            'refund_amount' => '25.00',
            'reason' => 'eligible cohort',
            'approved_refund_method' => ApprovedRefundMethod::Wallet,
            'status' => RefundStatus::Closed,
            'execution_transaction_id' => '100',
            'requested_by' => $user->id,
            'communication_channels' => [],
        ]);
    }
}
