<?php

namespace Tests\Unit\CentralWallet;

use App\CentralWallet\Application\WalletRefundAccountingDiscrepancyClassifier;
use App\CentralWallet\Domain\Enums\LedgerEntryStatus;
use App\CentralWallet\Domain\Enums\LedgerEntryType;
use App\CentralWallet\Domain\Enums\RefundMigrationLane;
use App\CentralWallet\Domain\Enums\RefundMigrationStatus;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralWallet;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletRefundMigration;
use App\Enums\ApprovedRefundMethod;
use App\Enums\RefundStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WalletRefundAccountingDiscrepancyClassifierTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_classifies_spoke_execution_without_desk_ledger_as_policy_violation(): void
    {
        $refund = $this->makeClosedWalletRefund('2667');

        $report = app(WalletRefundAccountingDiscrepancyClassifier::class)->buildReport(10);
        $row = collect($report['rows'])->firstWhere('refund_id', $refund->id);

        $this->assertNotNull($row);
        $this->assertSame('spoke_execution_without_desk_ledger', $row['classification']);
        $this->assertTrue($row['policy_violation']);
    }

    public function test_classifies_desk_ledger_credit_as_authoritative(): void
    {
        $refund = $this->makeClosedWalletRefund('9001');
        $customer = CentralCustomer::query()->create([
            'id' => (string) Str::uuid(),
            'central_wallet_id' => CentralWallet::query()->create(['id' => (string) Str::uuid(), 'status' => 'active'])->id,
            'status' => 'active',
        ]);

        CentralWalletLedgerEntry::query()->create([
            'central_wallet_id' => $customer->central_wallet_id,
            'entry_type' => LedgerEntryType::Credit,
            'amount' => '100.00',
            'currency' => 'INR',
            'status' => LedgerEntryStatus::Posted,
            'source_system' => 'radium_desk',
            'source_reference' => 'wallet_refund:'.$refund->id,
            'correlation_id' => (string) Str::uuid(),
            'business_reference' => $refund->reference_no,
            'posted_at' => now(),
        ]);

        $report = app(WalletRefundAccountingDiscrepancyClassifier::class)->buildReport(50);
        $row = collect($report['rows'])->firstWhere('refund_id', $refund->id);

        $this->assertSame('authoritative_desk_ledger', $row['classification']);
        $this->assertFalse($row['policy_violation']);
    }

    public function test_migration_reconciled_is_not_policy_violation(): void
    {
        $refund = $this->makeClosedWalletRefund('1');

        CentralWalletRefundMigration::query()->create([
            'id' => (string) Str::uuid(),
            'batch_id' => 'test-batch',
            'refund_id' => $refund->id,
            'refund_reference' => $refund->reference_no,
            'amount' => '50.00',
            'source_type' => 'rdservice.in',
            'source_reference' => '1',
            'lane' => RefundMigrationLane::Lane1SpokeCutover,
            'status' => RefundMigrationStatus::Reconciled,
            'idempotency_key' => 'test-'.$refund->id,
        ]);

        $report = app(WalletRefundAccountingDiscrepancyClassifier::class)->buildReport(50);
        $row = collect($report['rows'])->firstWhere('refund_id', $refund->id);

        $this->assertSame('migration_reconciled', $row['classification']);
        $this->assertFalse($row['policy_violation']);
    }

    private function makeClosedWalletRefund(string $executionTxnId): RefundRequest
    {
        $user = User::factory()->create();
        $reference = 'REF-'.Str::upper(Str::random(6));
        $orderId = 'RD'.Str::upper(Str::random(6));

        $order = Order::query()->create([
            'order_id' => $orderId,
            'customer_id' => (string) Str::uuid(),
            'serial_number' => 'SN-'.$orderId,
            'product_name' => 'Radium Device',
            'device_model' => 'Model X',
            'status' => 'active',
            'payment_amount' => '100.00',
            'customer_email' => 'discrepancy-test@example.com',
            'created_by' => $user->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'INC-'.substr($reference, -6),
            'category' => 'Refund',
            'source' => 'internal',
            'title' => 'Discrepancy classifier test',
            'description' => 'Test',
            'status' => 'open',
            'created_by' => $user->id,
        ]);

        return RefundRequest::query()->create([
            'order_id' => $order->id,
            'incident_id' => $incident->id,
            'reference_no' => $reference,
            'amount' => '100.00',
            'refund_amount' => '100.00',
            'reason' => 'test',
            'status' => RefundStatus::Closed,
            'approved_refund_method' => ApprovedRefundMethod::Wallet,
            'execution_transaction_id' => $executionTxnId,
            'requested_by' => $user->id,
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
            'communication_channels' => [],
        ]);
    }
}
