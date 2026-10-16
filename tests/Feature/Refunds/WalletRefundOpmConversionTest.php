<?php

namespace Tests\Feature\Refunds;

use App\Enums\ApprovedRefundMethod;
use App\Enums\RefundStatus;
use App\Enums\WalletRefundOpmConversionStatus;
use App\Enums\WalletRefundCreditSource;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use App\Models\WalletRefundOpmConversion;
use App\Services\WalletRefundOpmConversion\WalletRefundOpmConversionService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class WalletRefundOpmConversionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_site_disabled_fails_closed_on_submit(): void
    {
        $refund = $this->completedWalletRefund('RB1000001');
        $agent = $this->agentUser();

        $this->expectExceptionMessage('not enabled');

        app(WalletRefundOpmConversionService::class)->submit($agent, $refund);
    }

    public function test_full_lifecycle_with_mocked_spoke_and_cashfree(): void
    {
        config([
            'wallet_refund_opm_conversion.sites.radiumbox.com.enabled' => true,
            'order_lookup.spokes.radiumbox_com' => [
                'enabled' => true,
                'base_url' => 'https://box.test',
                'token' => 'token',
            ],
            'cashfree.api.app_id' => 'app',
            'cashfree.api.secret' => 'secret',
            'cashfree.api.base_url' => 'https://api.cashfree.test/pg',
        ]);

        Http::fake([
            'https://box.test/api/integrations/v1/wallet-opm-conversion-reservations' => Http::response([
                'data' => ['reservation_id' => '11111111-1111-1111-1111-111111111111', 'status' => 'reserved', 'idempotent_replay' => false],
            ], 201),
            'https://box.test/api/integrations/v1/wallet-opm-conversion-reservations/*/confirm' => Http::response([
                'data' => ['reservation_id' => '11111111-1111-1111-1111-111111111111', 'status' => 'reserved'],
            ], 200),
            'https://box.test/api/integrations/v1/wallet-opm-conversion-reservations/*/finalize-reversal' => Http::response([
                'data' => ['wallet_reversal_reference' => '9001', 'idempotent_replay' => false],
            ], 200),
            'https://api.cashfree.test/pg/orders/RB1000001/refunds/REF_cf_1' => Http::response([
                'refund_id' => 'REF_cf_1',
                'refund_amount' => 500,
                'refund_status' => 'SUCCESS',
            ], 200),
        ]);

        $refund = $this->completedWalletRefund('RB1000001', 500.00);
        $agent = $this->agentUser();
        $approver = $this->financeApprover();
        $payout = $this->financePayoutOperator();

        $service = app(WalletRefundOpmConversionService::class);
        $conversion = $service->submit($agent, $refund);
        $this->assertSame(WalletRefundOpmConversionStatus::PendingApproval, $conversion->status);
        $this->assertSame(WalletRefundCreditSource::LegacyLocal, $conversion->wallet_credit_source);

        $conversion = $service->approve($approver, $conversion);
        $this->assertSame(WalletRefundOpmConversionStatus::Reserved, $conversion->status);

        $this->expectExceptionMessage('different user');
        $service->recordPayout($approver, $conversion, 'RB1000001', 'REF_cf_1', '500.00');

        $conversion = $service->recordPayout($payout, $conversion->fresh(), 'RB1000001', 'REF_cf_1', '500.00');
        $this->assertSame(WalletRefundOpmConversionStatus::Completed, $conversion->status);
    }

    public function test_release_blocked_after_cashfree_evidence(): void
    {
        config(['wallet_refund_opm_conversion.sites.radiumbox.com.enabled' => true]);
        $refund = $this->completedWalletRefund('RB1000003', 300.00);
        $approver = $this->financeApprover();
        $releaseUser = User::factory()->create(['is_active' => true]);
        $releaseUser->givePermissionTo('refunds.wallet_opm_conversion.release');

        $conversion = WalletRefundOpmConversion::query()->create([
            'public_id' => (string) \Illuminate\Support\Str::uuid(),
            'refund_request_id' => $refund->id,
            'status' => WalletRefundOpmConversionStatus::Reserved,
            'site_code' => 'radiumbox.com',
            'wallet_credit_source' => WalletRefundCreditSource::LegacyLocal,
            'amount' => 300,
            'currency' => 'INR',
            'requester_id' => $this->agentUser()->id,
            'approver_id' => $approver->id,
            'site_reservation_id' => '11111111-1111-1111-1111-111111111111',
            'cashfree_refund_id' => 'REF_cf_blocked',
            'cashfree_verified_at' => now(),
        ]);

        $this->expectExceptionMessage('Cashfree payout evidence');
        app(WalletRefundOpmConversionService::class)->authorizeRelease($releaseUser, $conversion, 'Should not release');
    }

    public function test_central_wallet_credit_on_box_site_fails_closed(): void
    {
        config(['wallet_refund_opm_conversion.sites.radiumbox.com.enabled' => true]);
        $refund = $this->completedWalletRefund('RB1000004', 250.00);
        $refund->update([
            'execution_reference_no' => 'CW:99001',
            'execution_transaction_id' => '99001',
        ]);
        $this->seedWalletRefundCompletionAudit($refund, 'desk_first_central_wallet');

        $this->expectExceptionMessage('Desk Central Wallet');
        app(WalletRefundOpmConversionService::class)->submit($this->agentUser(), $refund);
    }

    public function test_cashfree_pending_enters_reconciliation_without_wallet_finalize(): void
    {
        config([
            'wallet_refund_opm_conversion.sites.radiumbox.com.enabled' => true,
            'order_lookup.spokes.radiumbox_com' => [
                'enabled' => true,
                'base_url' => 'https://box.test',
                'token' => 'token',
            ],
            'cashfree.api.app_id' => 'app',
            'cashfree.api.secret' => 'secret',
            'cashfree.api.base_url' => 'https://api.cashfree.test/pg',
        ]);

        Http::fake([
            'https://box.test/api/integrations/v1/wallet-opm-conversion-reservations' => Http::response([
                'data' => ['reservation_id' => '22222222-2222-2222-2222-222222222222', 'status' => 'reserved', 'idempotent_replay' => false],
            ], 201),
            'https://box.test/api/integrations/v1/wallet-opm-conversion-reservations/*/confirm' => Http::response([
                'data' => ['reservation_id' => '22222222-2222-2222-2222-222222222222', 'status' => 'reserved'],
            ], 200),
            'https://api.cashfree.test/pg/orders/RB1000005/refunds/REF_cf_pending' => Http::response([
                'refund_id' => 'REF_cf_pending',
                'refund_amount' => 400,
                'refund_status' => 'PENDING',
            ], 200),
        ]);

        $refund = $this->completedWalletRefund('RB1000005', 400.00);
        $service = app(WalletRefundOpmConversionService::class);
        $conversion = $service->approve($this->financeApprover(), $service->submit($this->agentUser(), $refund));
        $conversion = $service->recordPayout(
            $this->financePayoutOperator(),
            $conversion,
            'RB1000005',
            'REF_cf_pending',
            '400.00',
        );

        $this->assertSame(WalletRefundOpmConversionStatus::ReconciliationHold, $conversion->status);
        Http::assertNotSent(function ($request): bool {
            return str_contains($request->url(), 'finalize-reversal');
        });
    }

    public function test_order_refund_limit_counts_in_flight_conversion(): void
    {
        config(['wallet_refund_opm_conversion.sites.radiumbox.com.enabled' => true]);
        $order = $this->order('RB1000002', 1000);
        $refund = $this->completedWalletRefund('RB1000002', 400, $order);
        WalletRefundOpmConversion::query()->create([
            'public_id' => (string) \Illuminate\Support\Str::uuid(),
            'refund_request_id' => $refund->id,
            'status' => WalletRefundOpmConversionStatus::PendingApproval,
            'site_code' => 'radiumbox.com',
            'wallet_credit_source' => WalletRefundCreditSource::LegacyLocal,
            'amount' => 400,
            'currency' => 'INR',
            'requester_id' => $this->agentUser()->id, // conversion requester
        ]);

        $max = app(\App\Services\RefundCalculationService::class)->maximumRefundable($order);
        $this->assertSame(600.0, $max);
    }

    private function completedWalletRefund(string $orderId, float $amount = 500.0, ?Order $order = null): RefundRequest
    {
        $user = User::factory()->create();
        $order ??= $this->order($orderId, $amount + 100, $user);
        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'INC-'.substr($orderId, -6),
            'title' => 'Refund related incident',
            'description' => 'Incident description for refund testing.',
            'category' => 'Hardware',
            'source' => 'internal',
            'status' => 'open',
            'created_by' => $user->id,
        ]);

        $refund = RefundRequest::query()->create([
            'reference_no' => 'REF-'.substr($orderId, -6),
            'incident_id' => $incident->id,
            'order_id' => $order->id,
            'status' => RefundStatus::Completed,
            'approved_refund_method' => ApprovedRefundMethod::Wallet,
            'refund_amount' => $amount,
            'amount' => $amount,
            'requested_by' => $user->id,
            'reason' => 'Wallet refund completed.',
        ]);

        $this->seedWalletRefundCompletionAudit($refund, 'radiumbox_wallet');

        return $refund;
    }

    private function seedWalletRefundCompletionAudit(RefundRequest $refund, string $provider): void
    {
        AuditLog::query()->create([
            'user_id' => $refund->requested_by,
            'event' => 'refund.completed',
            'auditable_type' => RefundRequest::class,
            'auditable_id' => $refund->id,
            'new_values' => ['provider' => $provider],
        ]);
    }

    private function order(string $orderId, float $paymentAmount, ?User $user = null): Order
    {
        $user ??= User::factory()->create();

        return Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => 'SN-'.$orderId,
            'product_name' => 'Device',
            'device_model' => 'X',
            'status' => 'active',
            'payment_amount' => $paymentAmount,
            'cashfree_payment_id' => 'cf-pay-'.$orderId,
            'created_by' => $user->id,
        ]);
    }

    private function agentUser(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        return $user;
    }

    private function financeApprover(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo('refunds.wallet_opm_conversion.approve');

        return $user;
    }

    private function financePayoutOperator(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo('refunds.wallet_opm_conversion.payout');

        return $user;
    }
}
