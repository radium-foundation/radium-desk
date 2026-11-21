<?php

namespace Tests\Feature\CentralWallet;

use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralWallet;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use App\Enums\ApprovedRefundMethod;
use App\Enums\RefundStatus;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\Refunds\DeskFirstWalletRefundInterruptedCompletionRecorder;
use App\Support\Refunds\DeskFirstWalletRefundRecoveryInspector;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeskFirstWalletRefundInterruptedCompletionAuditTest extends TestCase
{
    use RefreshDatabase;

    private const SPOKE_URL = 'https://rdservice.in.test/api/integrations/v1/wallet-refunds';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config([
            'central_wallet.wallet_refund.desk_first_enabled' => true,
            'rdservice_in.wallet_refund_credit_enabled' => true,
            'order_lookup.spokes.rdservice_in.enabled' => true,
            'order_lookup.spokes.rdservice_in.base_url' => 'https://rdservice.in.test',
            'order_lookup.spokes.rdservice_in.token' => 'desk-rdservice-wallet-token',
        ]);
    }

    public function test_spoke_validation_failure_persists_desk_audit_without_completing_refund(): void
    {
        Http::fake([
            self::SPOKE_URL => Http::response(['message' => 'Order not found'], 404),
        ]);

        [$ops, $refund] = $this->pendingWalletRefund('RD90501', '1.00', 'REF-INT-A');

        $this->actingAs($ops)->post(route('refunds.complete', $refund), [])
            ->assertSessionHasErrors('refund');

        $refund->refresh();
        $this->assertSame(RefundStatus::PendingExecution, $refund->status);
        $this->assertNull($refund->executed_at);
        $this->assertSame(1, CentralWalletLedgerEntry::query()->where('business_reference', 'REF-INT-A')->count());

        $audit = AuditLog::query()
            ->where('auditable_id', $refund->id)
            ->where('event', DeskFirstWalletRefundInterruptedCompletionRecorder::AUDIT_EVENT)
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame('Order not found', $audit->new_values['spoke_audit_error'] ?? null);

        $notice = app(DeskFirstWalletRefundRecoveryInspector::class)->completionRecoveryNotice($refund);
        $this->assertNotNull($notice);
        $this->assertStringContainsString('Order not found', $notice);
    }

    public function test_retry_after_interrupted_completion_reuses_single_credit_and_completes(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            if ($attempts === 1) {
                return Http::response(['message' => 'temporary upstream fault'], 503);
            }

            return Http::response([
                'status' => 201,
                'data' => [
                    'wallet_transaction_id' => 88,
                    'wallet_reference' => 'CW:88',
                    'desk_refund_reference' => 'REF-INT-B',
                    'credit' => '2.00',
                    'currency' => 'INR',
                    'balance' => '0.00',
                ],
            ], 201);
        });

        [$ops, $refund] = $this->pendingWalletRefund('RD90502', '2.00', 'REF-INT-B');

        $this->actingAs($ops)->post(route('refunds.complete', $refund), [])
            ->assertSessionHasErrors('refund');

        $this->assertSame(
            1,
            AuditLog::query()
                ->where('auditable_id', $refund->id)
                ->where('event', DeskFirstWalletRefundInterruptedCompletionRecorder::AUDIT_EVENT)
                ->count(),
        );

        $this->actingAs($ops)->post(route('refunds.complete', $refund), [])
            ->assertSessionHas('status', 'refund-completed');

        $this->assertSame(1, CentralWalletLedgerEntry::query()->where('business_reference', 'REF-INT-B')->count());
        $refund->refresh();
        $this->assertContains($refund->status, [RefundStatus::Completed, RefundStatus::Closed]);
        $this->assertNotNull($refund->executed_at);
        $this->assertSame(2, $attempts);
    }

    /**
     * @return array{0: User, 1: RefundRequest}
     */
    private function pendingWalletRefund(string $orderId, string $amount, string $reference): array
    {
        $ops = User::factory()->create();
        $ops->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        $customerId = (string) Str::uuid();
        $cwid = (string) Str::uuid();
        CentralWallet::query()->create(['id' => $cwid, 'status' => 'active']);
        CentralCustomer::query()->create([
            'id' => $customerId,
            'central_wallet_id' => $cwid,
            'status' => 'active',
        ]);

        $order = Order::query()->create([
            'order_id' => $orderId,
            'customer_id' => $customerId,
            'serial_number' => 'SN',
            'product_name' => 'Device',
            'device_model' => 'X',
            'status' => 'active',
            'payment_amount' => $amount,
            'customer_email' => 'recovery@test.example',
            'created_by' => $ops->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'INC-'.substr($reference, -6),
            'category' => 'Refund',
            'source' => 'internal',
            'title' => 'Interrupted completion audit test',
            'description' => 'Test',
            'status' => 'open',
            'created_by' => $ops->id,
        ]);

        $refund = RefundRequest::query()->create([
            'order_id' => $order->id,
            'incident_id' => $incident->id,
            'reference_no' => $reference,
            'amount' => $amount,
            'refund_amount' => $amount,
            'reason' => 'desk-first interrupted audit test',
            'status' => RefundStatus::PendingExecution,
            'approved_refund_method' => ApprovedRefundMethod::Wallet,
            'requested_by' => $ops->id,
            'reviewed_by' => $ops->id,
            'reviewed_at' => now(),
            'communication_channels' => [],
        ]);

        return [$ops, $refund];
    }
}
