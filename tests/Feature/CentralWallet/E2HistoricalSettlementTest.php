<?php

namespace Tests\Feature\CentralWallet;

use App\CentralWallet\Application\E2HistoricalManualRefundSettlementService;
use App\CentralWallet\Application\E2HistoricalSettlementBatchGate;
use App\CentralWallet\Application\E2HistoricalSettlementDryRunService;
use App\CentralWallet\Application\E2HistoricalSettlementJournalImportService;
use App\CentralWallet\Application\E2HistoricalSettlementManifestLoader;
use App\CentralWallet\Application\E2HistoricalSettlementOrchestrator;
use App\CentralWallet\Domain\E2HistoricalSettlementClassification;
use App\CentralWallet\Domain\E2HistoricalSettlementIdempotencyKey;
use App\CentralWallet\Domain\Enums\RefundMigrationLane;
use App\CentralWallet\Domain\Enums\RefundMigrationStatus;
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
use Tests\TestCase;

class E2HistoricalSettlementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config([
            'central_wallet.e2_historical_settlement.manifest_path' => storage_path(
                'app/private/cw-e2-historical-settlement-manifest-p30-10-20.json',
            ),
            'central_wallet.refund_migration.execution_enabled' => false,
        ]);
    }

    public function test_manifest_validates_exact_52_rows_and_amount(): void
    {
        $manifest = app(E2HistoricalSettlementManifestLoader::class)->load();

        $this->assertSame(52, count($manifest['rows']));
        $this->assertSame('34517.00', $manifest['refund_amount']);
        $this->assertSame(E2HistoricalSettlementManifestLoader::BATCH_ID, $manifest['batch_id']);
        $this->assertSame(
            E2HistoricalSettlementClassification::OWNER_APPROVED_HISTORICAL_MANUAL_REFUND_SETTLEMENT,
            $manifest['settlement_classification'],
        );
    }

    public function test_import_creates_52_pending_rows_without_destination_cwid(): void
    {
        $this->seedTerminalRefundsFromManifest();

        $result = app(E2HistoricalSettlementJournalImportService::class)->import();

        $this->assertSame(52, $result['imported']);
        $this->assertSame(0, $result['prepared']);
        $this->assertSame(52, $result['pending']);
        $this->assertSame(52, $result['blocked']);
        $this->assertDatabaseCount('central_wallet_refund_migrations', 52);
        $this->assertSame(0, CentralWalletLedgerEntry::query()->count());

        $migration = CentralWalletRefundMigration::query()->where('refund_id', 1)->firstOrFail();
        $this->assertSame(RefundMigrationLane::Lane4OwnerApprovedHistoricalSettlement, $migration->lane);
        $this->assertSame(RefundMigrationStatus::Pending, $migration->status);
        $this->assertNull($migration->desk_customer_id);
        $this->assertNull($migration->cwid);
        $this->assertNull($migration->source_wallet_id);
        $this->assertSame('historical_manual_refund', $migration->source_type);
        $this->assertSame(
            E2HistoricalSettlementIdempotencyKey::forRefund(1),
            $migration->idempotency_key,
        );
        $this->assertSame(
            E2HistoricalSettlementClassification::OWNER_APPROVED_HISTORICAL_MANUAL_REFUND_SETTLEMENT,
            $migration->metadata['settlement_classification'],
        );
        $this->assertSame('unavailable_not_reconstructed', $migration->metadata['source_wallet_provenance']);
    }

    public function test_dry_run_blocks_all_rows_without_destination_cwid(): void
    {
        $this->seedTerminalRefundsFromManifest();
        app(E2HistoricalSettlementJournalImportService::class)->import();

        $report = app(E2HistoricalSettlementDryRunService::class)->run();

        $this->assertTrue($report['dry_run']);
        $this->assertSame(52, $report['journal_count']);
        $this->assertSame('34517.00', $report['journal_amount']);
        $this->assertSame(0, $report['executable_count']);
        $this->assertSame(52, $report['blocked_count']);
        $this->assertSame('0.00', $report['expected_spoke_debit']);
        $this->assertFalse($report['batch_executable']);
        $this->assertTrue(
            collect($report['batch_gate_blockers'])->contains(
                fn (array $b): bool => $b['code'] === 'missing_destination_cwid',
            ),
        );
    }

    public function test_execute_refuses_when_batch_not_executable(): void
    {
        $this->seedTerminalRefundsFromManifest();
        app(E2HistoricalSettlementJournalImportService::class)->import();
        config(['central_wallet.refund_migration.execution_enabled' => true]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('batch_gate_failed');

        app(E2HistoricalSettlementOrchestrator::class)->executeBatch(
            E2HistoricalSettlementManifestLoader::DEFAULT_OWNER_APPROVAL_REF,
        );
    }

    public function test_historical_settlement_credit_is_idempotent_without_spoke_debit(): void
    {
        [$customer, $refund] = $this->seedPreparedLane4Row(9003, 'REF-TEST-E2-SETTLE', '717.00');
        $migration = CentralWalletRefundMigration::query()->where('refund_id', 9003)->firstOrFail();
        $service = app(E2HistoricalManualRefundSettlementService::class);

        $first = $service->execute($migration, E2HistoricalSettlementManifestLoader::DEFAULT_OWNER_APPROVAL_REF, (string) Str::uuid(), 'test');
        $migration->refresh();
        $second = $service->execute($migration, E2HistoricalSettlementManifestLoader::DEFAULT_OWNER_APPROVAL_REF, (string) Str::uuid(), 'test');

        $this->assertSame(RefundMigrationStatus::Reconciled->value, $first['status']);
        $this->assertTrue($second['idempotent_replay'] ?? false);
        $this->assertSame(1, CentralWalletLedgerEntry::query()->where('entry_type', 'credit')->count());
        $this->assertDatabaseHas('central_wallet_ledger_entries', [
            'central_wallet_id' => $customer->central_wallet_id,
            'source_system' => 'radium-desk',
            'source_reference' => E2HistoricalSettlementIdempotencyKey::ledgerSourceReference(9003),
            'business_reference' => 'REF-TEST-E2-SETTLE',
            'amount' => '717.00',
        ]);

        $entry = CentralWalletLedgerEntry::query()->firstOrFail();
        $this->assertSame(
            E2HistoricalSettlementClassification::MIGRATION_TYPE,
            $entry->metadata['migration_type'],
        );
        $this->assertSame('unavailable_not_reconstructed', $entry->metadata['source_wallet_provenance']);

        $refund->refresh();
        $this->assertSame(RefundStatus::Closed, $refund->status);
        $this->assertNull($refund->execution_transaction_id);
    }

    public function test_batch_gate_requires_destination_cwid_for_each_row(): void
    {
        $this->seedTerminalRefundsFromManifest();
        app(E2HistoricalSettlementJournalImportService::class)->import();

        $blockers = app(E2HistoricalSettlementBatchGate::class)->evaluate(
            ownerApprovalRef: E2HistoricalSettlementManifestLoader::DEFAULT_OWNER_APPROVAL_REF,
        );

        $missing = collect($blockers)->where('code', 'missing_destination_cwid');
        $this->assertCount(52, $missing);
    }

    private function seedTerminalRefundsFromManifest(): void
    {
        $manifest = app(E2HistoricalSettlementManifestLoader::class)->load();
        $actor = User::factory()->create();

        foreach ($manifest['rows'] as $row) {
            $this->seedRefund(
                (int) $row['refund_id'],
                (string) $row['desk_refund_reference'],
                (string) $row['refund_amount'],
                (string) ($row['order_number'] ?? 'RD3400001'),
                $actor,
            );
        }
    }

    /**
     * @return array{0: CentralCustomer, 1: RefundRequest}
     */
    private function seedPreparedLane4Row(int $refundId, string $reference, string $amount): array
    {
        $cwid = (string) Str::uuid();
        CentralWallet::query()->forceCreate(['id' => $cwid, 'status' => 'active']);
        $customer = CentralCustomer::query()->create([
            'id' => (string) Str::uuid(),
            'central_wallet_id' => $cwid,
            'status' => 'active',
        ]);
        $actor = User::factory()->create();
        $refund = $this->seedRefund($refundId, $reference, $amount, 'RD3400001', $actor);

        CentralWalletRefundMigration::query()->create([
            'id' => (string) Str::uuid(),
            'batch_id' => E2HistoricalSettlementManifestLoader::BATCH_ID,
            'refund_id' => $refundId,
            'refund_reference' => $reference,
            'amount' => $amount,
            'source_type' => 'historical_manual_refund',
            'source_application' => null,
            'source_wallet_id' => null,
            'source_reference' => E2HistoricalSettlementIdempotencyKey::ledgerSourceReference($refundId),
            'desk_customer_id' => $customer->id,
            'cwid' => $cwid,
            'lane' => RefundMigrationLane::Lane4OwnerApprovedHistoricalSettlement,
            'status' => RefundMigrationStatus::Prepared,
            'idempotency_key' => E2HistoricalSettlementIdempotencyKey::forRefund($refundId),
            'order_number' => 'RD3400001',
            'identity_class' => 'E2',
            'prepared_at' => now(),
            'metadata' => [
                'settlement_classification' => E2HistoricalSettlementClassification::OWNER_APPROVED_HISTORICAL_MANUAL_REFUND_SETTLEMENT,
                'source_wallet_provenance' => 'unavailable_not_reconstructed',
                'forensic_report_ref' => E2HistoricalSettlementClassification::FORENSIC_REPORT_REF,
            ],
        ]);

        return [$customer, $refund];
    }

    private function seedRefund(int $refundId, string $reference, string $amount, string $orderNumber, User $actor): RefundRequest
    {
        $order = Order::factory()->create(['order_id' => $orderNumber]);
        $refund = RefundRequest::factory()->create([
            'id' => $refundId,
            'reference_no' => $reference,
            'refund_amount' => $amount,
            'amount' => $amount,
            'status' => RefundStatus::Closed,
            'approved_refund_method' => ApprovedRefundMethod::Wallet,
            'order_id' => $order->id,
            'executed_by' => $actor->id,
            'executed_at' => now(),
        ]);

        return $refund;
    }
}
