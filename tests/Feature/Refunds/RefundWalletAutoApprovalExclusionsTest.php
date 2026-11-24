<?php

namespace Tests\Feature\Refunds;

use App\Enums\ApprovedRefundMethod;
use App\Enums\CustomerPreferredRefundMethod;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\RefundDeductionProfile;
use App\Enums\RefundStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\BusinessHoldService;
use App\Services\IncidentReferenceService;
use App\Services\RefundRequestService;
use Database\Seeders\DeviceModelSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Refunds\Support\WalletAutoApprovalTestCase;

class RefundWalletAutoApprovalExclusionsTest extends WalletAutoApprovalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DeviceModelSeeder::class);
    }

    public function test_cashfree_execution_path_stays_manual_through_create_and_approve(): void
    {
        config([
            'refunds.wallet_auto_approval.enabled' => false,
            'central_wallet.wallet_refund.desk_first_enabled' => true,
        ]);

        [$agent, $order] = $this->bindOrder('RD3438101', '1100.00');
        $payload = $this->fullRefundPayload($order);
        $payload['customer_preferred_method'] = CustomerPreferredRefundMethod::Opm->value;

        $refund = app(RefundRequestService::class)->create($agent, $payload, Request::create('/'));

        $this->assertSame(RefundStatus::Pending, $refund->status);
        $this->assertNull($refund->approved_refund_method);

        $ops = User::factory()->create(['is_active' => true]);
        $ops->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        $approved = app(RefundRequestService::class)->approve(
            $refund,
            $ops,
            [
                'approved_refund_method' => ApprovedRefundMethod::Cashfree->value,
                'deduction_profile_key' => RefundDeductionProfile::FullRefund->value,
                'review_notes' => 'Manual Cashfree path.',
            ],
            Request::create('/'),
        );

        $this->assertSame(ApprovedRefundMethod::Cashfree, $approved->approved_refund_method);
        $this->assertSame(RefundStatus::PendingExecution, $approved->status);
    }

    public function test_bank_transfer_stays_manual_at_create_and_supports_manual_approve(): void
    {
        config(['refunds.wallet_auto_approval.enabled' => false]);

        [$agent, $order] = $this->bindOrder('RD3438102', '900.00');
        $payload = $this->fullRefundPayload($order);
        $payload['customer_preferred_method'] = CustomerPreferredRefundMethod::Opm->value;

        $refund = app(RefundRequestService::class)->create($agent, $payload, Request::create('/'));
        $this->assertSame(RefundStatus::Pending, $refund->status);

        $ops = User::factory()->create(['is_active' => true]);
        $ops->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        $approved = app(RefundRequestService::class)->approve(
            $refund,
            $ops,
            [
                'approved_refund_method' => ApprovedRefundMethod::BankTransfer->value,
                'deduction_profile_key' => RefundDeductionProfile::FullRefund->value,
                'refund_amount' => 900,
                'partial_difference_reason' => 'partial_refund',
                'review_notes' => 'Manual bank transfer.',
            ],
            Request::create('/'),
        );

        $this->assertSame(ApprovedRefundMethod::BankTransfer, $approved->approved_refund_method);
    }

    public function test_upi_stays_manual_at_create_and_supports_manual_approve(): void
    {
        config(['refunds.wallet_auto_approval.enabled' => false]);

        [$agent, $order] = $this->bindOrder('RD3438103', '850.00');
        $payload = $this->fullRefundPayload($order);
        $payload['customer_preferred_method'] = CustomerPreferredRefundMethod::Opm->value;

        $refund = app(RefundRequestService::class)->create($agent, $payload, Request::create('/'));

        $ops = User::factory()->create(['is_active' => true]);
        $ops->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        $approved = app(RefundRequestService::class)->approve(
            $refund,
            $ops,
            [
                'approved_refund_method' => ApprovedRefundMethod::Upi->value,
                'deduction_profile_key' => RefundDeductionProfile::FullRefund->value,
                'review_notes' => 'Manual UPI.',
            ],
            Request::create('/'),
        );

        $this->assertSame(ApprovedRefundMethod::Upi, $approved->approved_refund_method);
        $this->assertSame(RefundStatus::PendingExecution, $approved->status);
    }

    public function test_other_method_stays_manual_at_create_and_supports_manual_approve(): void
    {
        config(['refunds.wallet_auto_approval.enabled' => false]);

        [$agent, $order] = $this->bindOrder('RD3438104', '700.00');
        $payload = $this->fullRefundPayload($order);
        $payload['customer_preferred_method'] = CustomerPreferredRefundMethod::Opm->value;

        $refund = app(RefundRequestService::class)->create($agent, $payload, Request::create('/'));

        $ops = User::factory()->create(['is_active' => true]);
        $ops->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        $approved = app(RefundRequestService::class)->approve(
            $refund,
            $ops,
            [
                'approved_refund_method' => ApprovedRefundMethod::Other->value,
                'deduction_profile_key' => RefundDeductionProfile::FullRefund->value,
                'review_notes' => 'Manual other.',
            ],
            Request::create('/'),
        );

        $this->assertSame(ApprovedRefundMethod::Other, $approved->approved_refund_method);
    }

    public function test_active_business_hold_blocks_second_refund_create(): void
    {
        $this->enablePilotConfig();
        Notification::fake();

        [$agent, $order] = $this->bindOrder('RD3438105', '1000.00');

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Cashfree,
            'title' => 'Hold gate case',
            'description' => 'Refund hold active before second create.',
            'status' => IncidentStatus::Open,
            'assigned_to_user_id' => $agent->id,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);

        $payload = $this->fullRefundPayload($order, $incident->id);
        app(RefundRequestService::class)->create($agent, $payload, Request::create('/'));

        $this->assertTrue(app(BusinessHoldService::class)->hasActiveHold($incident->fresh()));

        $this->expectException(ValidationException::class);

        app(RefundRequestService::class)->create($agent, $payload, Request::create('/'));
    }

    public function test_hardware_gate_blocks_general_refund_create(): void
    {
        $this->enablePilotConfig();

        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $customerId = (string) Str::uuid();
        $order = Order::query()->create([
            'order_id' => 'RBP8106',
            'customer_id' => $customerId,
            'serial_number' => 'SN-RBP8106',
            'product_name' => 'Hardware SKU',
            'device_model' => 'Model',
            'status' => 'active',
            'payment_amount' => '1200.00',
            'customer_email' => 'hw@example.com',
            'created_by' => $agent->id,
        ]);

        $this->expectException(ValidationException::class);

        app(RefundRequestService::class)->create(
            $agent,
            $this->fullRefundPayload($order),
            Request::create('/'),
        );
    }

    public function test_resolver_rejection_blocks_auto_approval_for_unconfigured_rdservice_net(): void
    {
        $this->enablePilotConfig();
        config([
            'rdservice_net.wallet_refund_credit_enabled' => false,
            'order_lookup.spokes.rdservice_net.enabled' => false,
        ]);

        [$agent, $order] = $this->bindOrder('RN8107', '600.00');

        $refund = app(RefundRequestService::class)->create(
            $agent,
            $this->fullRefundPayload($order),
            Request::create('/'),
        );

        $this->assertSame(RefundStatus::Pending, $refund->status);
        $this->assertNull($refund->approved_refund_method);
    }

    public function test_pilot_allowlist_excludes_order_when_pilot_spokes_narrowed(): void
    {
        $this->enablePilotConfig(pilotSpokes: 'rdservice.in', deskFirstSpokes: 'rdservice.in,radiumbox.com');

        [$agent, $order] = $this->bindOrder('RB8108', '800.00');

        $refund = app(RefundRequestService::class)->create(
            $agent,
            $this->fullRefundPayload($order),
            Request::create('/'),
        );

        $this->assertSame(RefundStatus::Pending, $refund->status);
    }

    public function test_desk_first_allowlist_mismatch_blocks_auto_when_pilot_is_wider(): void
    {
        $this->enablePilotConfig(pilotSpokes: 'rdservice.in,radiumbox.com', deskFirstSpokes: 'rdservice.in');

        [$agent, $order] = $this->bindOrder('RB8109', '750.00');

        $refund = app(RefundRequestService::class)->create(
            $agent,
            $this->fullRefundPayload($order),
            Request::create('/'),
        );

        $this->assertSame(RefundStatus::Pending, $refund->status);
    }

    public function test_auto_approval_never_assigns_non_wallet_approved_method(): void
    {
        $this->enablePilotConfig();

        [$agent, $order] = $this->bindOrder('RD3438110', '999.00');

        $refund = app(RefundRequestService::class)->create(
            $agent,
            $this->fullRefundPayload($order),
            Request::create('/'),
        );

        $this->assertSame(RefundStatus::PendingExecution, $refund->status);
        $this->assertSame(ApprovedRefundMethod::Wallet, $refund->approved_refund_method);
        $this->assertNotSame(ApprovedRefundMethod::Cashfree, $refund->approved_refund_method);
    }
}
