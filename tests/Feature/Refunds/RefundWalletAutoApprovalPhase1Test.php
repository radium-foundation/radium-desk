<?php

namespace Tests\Feature\Refunds;

use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralWallet;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use App\Enums\ApprovedRefundMethod;
use App\Enums\CustomerPreferredRefundMethod;
use App\Enums\RefundDeductionProfile;
use App\Enums\RefundDifferenceReason;
use App\Enums\RefundStatus;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use App\Notifications\RefundRequestSubmittedNotification;
use App\Services\RefundRequestService;
use App\Services\Refunds\RefundWalletAutoApprovalEvaluator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class RefundWalletAutoApprovalPhase1Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->withoutVite();
    }

    private function seedRefundApprover(): User
    {
        $approver = User::factory()->create(['is_active' => true]);
        $approver->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        return $approver;
    }

    private function enablePilotConfig(): void
    {
        config([
            'refunds.wallet_auto_approval.enabled' => true,
            'refunds.wallet_auto_approval.spokes' => 'rdservice.in,radiumbox.com',
            'refunds.wallet_auto_approval.max_amount' => '2000',
            'central_wallet.wallet_refund.desk_first_enabled' => true,
            'central_wallet.wallet_refund.desk_first_spoke_allowlist' => 'rdservice.in,radiumbox.com,rdservice.net',
        ]);
    }

    /**
     * @return array{0: User, 1: Order, 2: string}
     */
    private function bindOrder(string $businessOrderId, string $paymentAmount = '1500.00'): array
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $customerId = (string) Str::uuid();
        $cwid = (string) Str::uuid();
        CentralWallet::query()->create(['id' => $cwid, 'status' => 'active']);
        CentralCustomer::query()->create([
            'id' => $customerId,
            'central_wallet_id' => $cwid,
            'status' => 'active',
        ]);

        $order = Order::query()->create([
            'order_id' => $businessOrderId,
            'customer_id' => $customerId,
            'serial_number' => 'SN-'.$businessOrderId,
            'product_name' => 'Device',
            'device_model' => 'Model X',
            'status' => 'active',
            'payment_amount' => $paymentAmount,
            'customer_email' => 'auto-refund@example.com',
            'created_by' => $agent->id,
        ]);

        return [$agent, $order, $customerId];
    }

    private function fullRefundPayload(Order $order, ?int $incidentId = null): array
    {
        return [
            'order_id' => $order->id,
            'incident_id' => $incidentId,
            'deduction_profile_key' => RefundDeductionProfile::FullRefund->value,
            'reason' => 'Customer eligible for full wallet refund under pilot rules.',
            'remarks' => 'Pilot auto-approval path test.',
            'customer_preferred_method' => CustomerPreferredRefundMethod::Wallet->value,
        ];
    }

    public function test_flag_off_preserves_pending_status_and_notifier(): void
    {
        config(['refunds.wallet_auto_approval.enabled' => false]);
        Notification::fake();
        $approver = $this->seedRefundApprover();

        [$agent, $order] = $this->bindOrder('RD3437999', '999.00');

        $service = app(RefundRequestService::class);
        $refund = $service->create($agent, $this->fullRefundPayload($order), Request::create('/'));

        $this->assertSame(RefundStatus::Pending, $refund->status);
        $this->assertNull($refund->approved_refund_method);
        Notification::assertSentTo($approver, RefundRequestSubmittedNotification::class);
    }

    public function test_eligible_rdservice_in_auto_approves_to_pending_execution(): void
    {
        $this->enablePilotConfig();
        Notification::fake();

        [$agent, $order] = $this->bindOrder('RD3438001', '1500.00');

        $refund = app(RefundRequestService::class)->create(
            $agent,
            $this->fullRefundPayload($order),
            Request::create('/'),
        );

        $refund->refresh();
        $this->assertSame(RefundStatus::PendingExecution, $refund->status);
        $this->assertSame(ApprovedRefundMethod::Wallet, $refund->approved_refund_method);
        $this->assertNull($refund->reviewed_by);
        $this->assertSame(RefundWalletAutoApprovalEvaluator::REVIEW_NOTES, $refund->review_notes);

        $audit = AuditLog::query()
            ->where('auditable_type', RefundRequest::class)
            ->where('auditable_id', $refund->id)
            ->where('event', 'refund.approved')
            ->latest('id')
            ->first();
        $this->assertNotNull($audit);
        $this->assertSame('auto', $audit->new_values['approval_source'] ?? null);

        Notification::assertNothingSent();
        $this->assertSame(0, CentralWalletLedgerEntry::query()->count());
        $this->assertNull($refund->executed_at);
    }

    public function test_eligible_radiumbox_com_auto_approves(): void
    {
        $this->enablePilotConfig();
        Notification::fake();

        [$agent, $order] = $this->bindOrder('RB403', '1200.00');

        $refund = app(RefundRequestService::class)->create(
            $agent,
            $this->fullRefundPayload($order),
            Request::create('/'),
        );

        $this->assertSame(RefundStatus::PendingExecution, $refund->fresh()->status);
        Notification::assertNothingSent();
    }

    public function test_opm_preferred_method_never_auto_approves(): void
    {
        $this->enablePilotConfig();
        Notification::fake();
        $approver = $this->seedRefundApprover();

        [$agent, $order] = $this->bindOrder('RD3438002', '900.00');
        $payload = $this->fullRefundPayload($order);
        $payload['customer_preferred_method'] = CustomerPreferredRefundMethod::Opm->value;

        $refund = app(RefundRequestService::class)->create($agent, $payload, Request::create('/'));

        $this->assertSame(RefundStatus::Pending, $refund->status);
        Notification::assertSentTo($approver, RefundRequestSubmittedNotification::class);
    }

    public function test_rdservice_net_never_auto_approves(): void
    {
        $this->enablePilotConfig();
        Notification::fake();

        [$agent, $order] = $this->bindOrder('RN92', '500.00');
        config([
            'rdservice_net.wallet_refund_credit_enabled' => true,
            'order_lookup.spokes.rdservice_net.enabled' => true,
            'order_lookup.spokes.rdservice_net.base_url' => 'https://net.test',
            'order_lookup.spokes.rdservice_net.token' => 'token',
        ]);

        $refund = app(RefundRequestService::class)->create(
            $agent,
            $this->fullRefundPayload($order),
            Request::create('/'),
        );

        $this->assertSame(RefundStatus::Pending, $refund->status);
    }

    public function test_partial_refund_amount_stays_pending(): void
    {
        $this->enablePilotConfig();

        [$agent, $order] = $this->bindOrder('RD3438003', '2000.00');
        $payload = $this->fullRefundPayload($order);
        $payload['amount'] = 1500;
        $payload['partial_difference_reason'] = RefundDifferenceReason::PartialRefund->value;

        $refund = app(RefundRequestService::class)->create($agent, $payload, Request::create('/'));

        $this->assertSame(RefundStatus::Pending, $refund->status);
    }

    public function test_amount_above_cap_stays_pending(): void
    {
        $this->enablePilotConfig();

        [$agent, $order] = $this->bindOrder('RD3438004', '2500.00');

        $refund = app(RefundRequestService::class)->create(
            $agent,
            $this->fullRefundPayload($order),
            Request::create('/'),
        );

        $this->assertSame(RefundStatus::Pending, $refund->status);
    }

    public function test_prior_refund_on_order_blocks_auto_approval(): void
    {
        $this->enablePilotConfig();

        [$agent, $order] = $this->bindOrder('RD3438005', '1000.00');

        RefundRequest::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'REF-PRIOR-1',
            'amount' => '100.00',
            'refund_amount' => '100.00',
            'reason' => 'Earlier completed refund blocks pilot auto-approval.',
            'status' => RefundStatus::Completed,
            'requested_by' => $agent->id,
        ]);

        $refund = app(RefundRequestService::class)->create(
            $agent,
            $this->fullRefundPayload($order),
            Request::create('/'),
        );

        $this->assertSame(RefundStatus::Pending, $refund->status);
    }

    public function test_desk_first_disabled_blocks_auto_approval(): void
    {
        $this->enablePilotConfig();
        config(['central_wallet.wallet_refund.desk_first_enabled' => false]);

        [$agent, $order] = $this->bindOrder('RD3438006', '800.00');

        $refund = app(RefundRequestService::class)->create(
            $agent,
            $this->fullRefundPayload($order),
            Request::create('/'),
        );

        $this->assertSame(RefundStatus::Pending, $refund->status);
    }

    public function test_active_pending_refund_blocks_second_auto_approval(): void
    {
        $this->enablePilotConfig();

        [$agent, $order] = $this->bindOrder('RD3438008', '900.00');

        RefundRequest::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'REF-ACTIVE-PENDING',
            'amount' => '100.00',
            'refund_amount' => '100.00',
            'reason' => 'Another pending refund blocks pilot auto-approval.',
            'status' => RefundStatus::Pending,
            'requested_by' => $agent->id,
        ]);

        $refund = app(RefundRequestService::class)->create(
            $agent,
            $this->fullRefundPayload($order),
            Request::create('/'),
        );

        $this->assertSame(RefundStatus::Pending, $refund->status);
    }

    public function test_revoked_refund_history_blocks_auto_approval(): void
    {
        $this->enablePilotConfig();

        [$agent, $order] = $this->bindOrder('RD3438009', '750.00');

        RefundRequest::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'REF-REVOKED',
            'amount' => '50.00',
            'refund_amount' => '50.00',
            'reason' => 'Revoked history blocks pilot auto-approval.',
            'status' => RefundStatus::Revoked,
            'requested_by' => $agent->id,
        ]);

        $refund = app(RefundRequestService::class)->create(
            $agent,
            $this->fullRefundPayload($order),
            Request::create('/'),
        );

        $this->assertSame(RefundStatus::Pending, $refund->status);
    }

    public function test_manual_cashfree_approval_after_create_still_supported(): void
    {
        config([
            'refunds.wallet_auto_approval.enabled' => false,
            'central_wallet.wallet_refund.desk_first_enabled' => true,
        ]);

        [$agent, $order] = $this->bindOrder('RD3438011', '1100.00');

        $refund = app(RefundRequestService::class)->create(
            $agent,
            $this->fullRefundPayload($order),
            Request::create('/'),
        );

        $this->assertSame(RefundStatus::Pending, $refund->status);

        $ops = User::factory()->create(['is_active' => true]);
        $ops->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        $approved = app(RefundRequestService::class)->approve(
            $refund,
            $ops,
            [
                'approved_refund_method' => ApprovedRefundMethod::Cashfree->value,
                'deduction_profile_key' => RefundDeductionProfile::FullRefund->value,
                'review_notes' => 'Manual Cashfree approval regression.',
            ],
            Request::create('/'),
        );

        $this->assertSame(RefundStatus::PendingExecution, $approved->status);
        $this->assertSame(ApprovedRefundMethod::Cashfree, $approved->approved_refund_method);
    }

    public function test_unbound_central_wallet_customer_blocks_auto_approval(): void
    {
        $this->enablePilotConfig();

        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $order = Order::query()->create([
            'order_id' => 'RD3438007',
            'serial_number' => 'SN-8007',
            'product_name' => 'Device',
            'device_model' => 'Model',
            'status' => 'active',
            'payment_amount' => '800.00',
            'customer_email' => 'unbound@example.com',
            'created_by' => $agent->id,
        ]);

        $refund = app(RefundRequestService::class)->create(
            $agent,
            $this->fullRefundPayload($order),
            Request::create('/'),
        );

        $this->assertSame(RefundStatus::Pending, $refund->status);
    }
}
