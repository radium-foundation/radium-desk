<?php

namespace Tests\Feature\CentralWallet;

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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CentralWalletIntegrationRefundLedgerCreditGateTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'cw-refund-ledger-credit-gate-token';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'central_wallet.enabled' => true,
            'central_wallet.api_enabled' => true,
            'central_wallet.integration_token' => self::TOKEN,
            'central_wallet.direct_ledger_debit.enabled' => true,
        ]);
    }

    public function test_blocks_unpaired_refund_credit_for_known_refund_reference(): void
    {
        [$cwid, $reference] = $this->seedAuthorityGapRefund();

        $this->authenticatedAs('rdservice.in')->postJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-entries", [
            'idempotency_key' => 'refund-credit-blocked-1',
            'entry_type' => 'credit',
            'amount' => '597.00',
            'business_reference' => $reference,
        ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'wallet_refund_requires_paired_reconciliation');

        $this->assertSame(0, CentralWalletLedgerEntry::query()->count());
    }

    public function test_allows_unrelated_credit_without_desk_refund_reference(): void
    {
        $cwid = $this->createWallet();

        $this->authenticatedAs('rdservice.in')->postJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-entries", [
            'idempotency_key' => 'checkout-credit-1',
            'entry_type' => 'credit',
            'amount' => '10.00',
            'business_reference' => 'RD99999',
        ])
            ->assertCreated();

        $this->assertDatabaseHas('central_wallet_ledger_entries', [
            'business_reference' => 'RD99999',
            'amount' => '10.00',
        ]);
    }

    public function test_allows_credit_when_refund_reference_unknown_to_desk(): void
    {
        $cwid = $this->createWallet();

        $this->authenticatedAs('rdservice.in')->postJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-entries", [
            'idempotency_key' => 'orphan-ref-credit',
            'entry_type' => 'credit',
            'amount' => '5.00',
            'business_reference' => 'REF-9999999',
        ])
            ->assertCreated();
    }

    public function test_blocks_integration_credit_when_reconciled_refund_already_has_posted_ledger_credit(): void
    {
        [$cwid, $reference] = $this->seedReconciledRefundFixture();

        $this->authenticatedAs('rdservice.in')->postJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-entries", [
            'idempotency_key' => 'reconciled-duplicate-attempt-1',
            'entry_type' => 'credit',
            'amount' => '597.00',
            'business_reference' => $reference,
        ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'refund_ledger_credit_already_posted');

        $this->assertSame(1, CentralWalletLedgerEntry::query()->where('business_reference', $reference)->count());
    }

    public function test_blocks_duplicate_integration_credit_with_different_idempotency_key(): void
    {
        [$cwid, $reference] = $this->seedReconciledRefundFixture();

        $this->authenticatedAs('rdservice.in')->postJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-entries", [
            'idempotency_key' => 'duplicate-key-alpha',
            'entry_type' => 'credit',
            'amount' => '597.00',
            'business_reference' => $reference,
        ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'refund_ledger_credit_already_posted');

        $this->authenticatedAs('rdservice.in')->postJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-entries", [
            'idempotency_key' => 'duplicate-key-beta',
            'entry_type' => 'credit',
            'amount' => '597.00',
            'business_reference' => $reference,
        ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'refund_ledger_credit_already_posted');

        $this->assertSame(1, CentralWalletLedgerEntry::query()->where('business_reference', $reference)->count());
    }

    public function test_idempotent_retry_returns_same_result_without_second_append(): void
    {
        $cwid = $this->createWallet();

        $payload = [
            'idempotency_key' => 'idem-unrelated-credit',
            'entry_type' => 'credit',
            'amount' => '15.00',
            'business_reference' => 'RD-IDEM-9001',
        ];

        $first = $this->authenticatedAs('rdservice.in')->postJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-entries", $payload)
            ->assertCreated()
            ->json('ledger_entry_id');

        $this->authenticatedAs('rdservice.in')->postJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-entries", $payload)
            ->assertSuccessful()
            ->assertJsonPath('ledger_entry_id', $first);

        $this->assertSame(1, CentralWalletLedgerEntry::query()->where('business_reference', 'RD-IDEM-9001')->count());
    }

    public function test_debit_entries_are_not_subject_to_refund_credit_gate(): void
    {
        [$cwid, $reference] = $this->seedAuthorityGapRefund();

        $this->authenticatedAs('rdservice.in')->postJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-entries", [
            'idempotency_key' => 'prefund-debit-balance',
            'entry_type' => 'credit',
            'amount' => '10.00',
            'business_reference' => 'RD-PREFUND',
        ])
            ->assertCreated();

        $this->authenticatedAs('rdservice.in')->postJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-entries", [
            'idempotency_key' => 'refund-debit-allowed',
            'entry_type' => 'debit',
            'amount' => '1.00',
            'business_reference' => $reference,
        ])
            ->assertCreated();
    }

    /**
     * @return array{0: string, 1: string, 2: int, 3: string}
     */
    private function seedReconciledRefundFixture(): array
    {
        [$cwid, $reference, $refundId, $customerId] = $this->seedAuthorityGapRefund();

        $ledgerId = CentralWalletLedgerEntry::query()->create([
            'central_wallet_id' => $cwid,
            'entry_type' => 'credit',
            'amount' => '597.00',
            'currency' => 'INR',
            'status' => 'posted',
            'source_system' => 'rdservice.in',
            'correlation_id' => (string) Str::uuid(),
            'business_reference' => $reference,
            'posted_at' => now(),
        ])->id;

        CentralWalletRefundMigration::query()->create([
            'id' => (string) Str::uuid(),
            'batch_id' => 'batch-test',
            'refund_id' => $refundId,
            'refund_reference' => $reference,
            'amount' => '597.00',
            'source_type' => 'spoke_wallet',
            'source_application' => 'rdservice.in',
            'source_wallet_id' => 2667,
            'cwid' => $cwid,
            'desk_customer_id' => $customerId,
            'lane' => RefundMigrationLane::Lane1SpokeCutover,
            'status' => RefundMigrationStatus::Reconciled,
            'idempotency_key' => 'refund-'.$refundId,
            'destination_ledger_entry_id' => $ledgerId,
            'balance_migration_operation_id' => (string) Str::uuid(),
            'source_debit_reference' => '9001',
            'source_reference' => RefundMigrationIdempotencyKey::ledgerSourceReference($refundId),
            'executed_at' => now(),
        ]);

        return [$cwid, $reference, $refundId, $customerId];
    }

    /**
     * @return array{0: string, 1: string, 2: int, 3: string}
     */
    private function seedAuthorityGapRefund(): array
    {
        $cwid = $this->createWallet();
        $customerId = (string) Str::uuid();
        CentralCustomer::query()->create([
            'id' => $customerId,
            'central_wallet_id' => $cwid,
            'status' => 'active',
        ]);

        $user = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RD17641',
            'customer_id' => $customerId,
            'serial_number' => 'SN',
            'product_name' => 'Device',
            'device_model' => 'X',
            'status' => 'active',
            'payment_amount' => '597.00',
            'customer_email' => 'gap@example.com',
            'created_by' => $user->id,
        ]);

        $reference = 'REF-67395';
        $refund = RefundRequest::query()->create([
            'order_id' => $order->id,
            'reference_no' => $reference,
            'amount' => '597.00',
            'refund_amount' => '597.00',
            'reason' => 'gate test',
            'status' => RefundStatus::Closed,
            'approved_refund_method' => ApprovedRefundMethod::Wallet,
            'execution_transaction_id' => '2667',
            'requested_by' => $user->id,
        ]);

        return [$cwid, $reference, (int) $refund->id, $customerId];
    }

    private function createWallet(): string
    {
        $cwid = (string) Str::uuid();
        CentralWallet::query()->create(['id' => $cwid, 'status' => 'active']);

        return $cwid;
    }

    private function authenticatedAs(string $siteCode): self
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.self::TOKEN,
            'X-Site-Code' => $siteCode,
        ]);
    }
}
