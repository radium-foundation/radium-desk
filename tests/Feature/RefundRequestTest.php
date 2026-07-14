<?php

namespace Tests\Feature;

use App\Enums\ApprovedRefundMethod;
use App\Enums\CustomerPreferredRefundMethod;
use App\Enums\RefundStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\RefundReferenceService;
use App\Services\RefundRequestService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RefundRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function createOrder(User $user, string $orderId = 'RD2000001', ?float $paymentAmount = null): Order
    {
        return Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => 'SN-'.$orderId,
            'product_name' => 'Radium Device',
            'device_model' => 'Model X',
            'status' => 'active',
            'payment_amount' => $paymentAmount,
            'created_by' => $user->id,
        ]);
    }

    private function createIncident(User $user, Order $order, string $referenceNo = 'INC-2026-000100'): Incident
    {
        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => $referenceNo,
            'category' => 'Hardware',
            'source' => 'internal',
            'title' => 'Refund related incident',
            'description' => 'Incident description for refund testing.',
            'status' => 'open',
            'created_by' => $user->id,
        ]);
    }

    public function test_agent_can_view_and_create_refunds_but_cannot_review_or_delete(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = $this->createOrder($admin);
        $refund = RefundRequest::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'REF-2026-000001',
            'amount' => 1000,
            'reason' => 'Duplicate payment received from customer.',
            'status' => RefundStatus::Pending,
            'requested_by' => $agent->id,
        ]);

        $this->actingAs($agent)->get(route('refunds.index'))->assertOk();
        $this->actingAs($agent)->get(route('refunds.show', $refund))->assertOk();
        $this->actingAs($agent)->get(route('refunds.create'))->assertOk();

        $this->actingAs($agent)->post(route('refunds.approve', $refund), [
            'approved_refund_method' => ApprovedRefundMethod::Cashfree->value,
        ])->assertForbidden();

        $this->actingAs($agent)->delete(route('refunds.destroy', $refund))->assertForbidden();
    }

    public function test_refund_reference_numbers_increment_per_year(): void
    {
        RefundRequest::query()->create([
            'order_id' => $this->createOrder(User::factory()->create())->id,
            'reference_no' => 'REF-'.now()->format('Y').'-000007',
            'amount' => 100,
            'reason' => 'Test refund reason for reference generation.',
            'status' => RefundStatus::Pending,
            'requested_by' => User::factory()->create()->id,
        ]);

        $next = app(RefundReferenceService::class)->generate();

        $this->assertSame('REF-'.now()->format('Y').'-000008', $next);
    }

    public function test_user_can_create_refund_with_auto_generated_reference(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = $this->createOrder($agent);
        $incident = $this->createIncident($agent, $order);

        $response = $this->actingAs($agent)->post(route('refunds.store'), [
            'order_id' => $order->id,
            'incident_id' => $incident->id,
            'amount' => 1500.50,
            'reason' => 'Customer returned device within warranty period.',
            'customer_preferred_method' => CustomerPreferredRefundMethod::Wallet->value,
        ]);

        $refund = RefundRequest::query()->first();

        $this->assertNotNull($refund);
        $this->assertMatchesRegularExpression('/^REF-\d{4}-\d{6}$/', $refund->reference_no);
        $this->assertSame(RefundStatus::Pending, $refund->status);
        $this->assertSame($incident->id, $refund->incident_id);
        $this->assertSame(CustomerPreferredRefundMethod::Wallet, $refund->customer_preferred_method);
        $response->assertRedirect(route('refunds.show', $refund));

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'created',
            'auditable_type' => $refund->getMorphClass(),
            'auditable_id' => $refund->id,
            'user_id' => $agent->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'refund.requested',
            'auditable_type' => $refund->getMorphClass(),
            'auditable_id' => $refund->id,
        ]);
    }

    public function test_index_supports_filters(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = $this->createOrder($admin, 'RD3000001');
        $incident = $this->createIncident($admin, $order, 'INC-2026-000300');

        RefundRequest::query()->create([
            'order_id' => $order->id,
            'incident_id' => $incident->id,
            'reference_no' => 'REF-2026-000300',
            'amount' => 500,
            'reason' => 'Filter test refund request for listing page.',
            'status' => RefundStatus::Pending,
            'requested_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->get(route('refunds.index', [
                'reference_no' => '000300',
                'order_id' => 'RD3000001',
                'incident_reference_no' => 'INC-2026-000300',
                'status' => RefundStatus::Pending->value,
                'requested_by' => $admin->id,
            ]))
            ->assertOk()
            ->assertSee('REF-2026-000300')
            ->assertSee('RD3000001')
            ->assertSee('INC-2026-000300')
            ->assertSee($admin->name);
    }

    public function test_admin_can_approve_pending_refund_to_pending_execution(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = $this->createOrder($admin, paymentAmount: 1000);
        $refund = RefundRequest::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'REF-2026-000400',
            'amount' => 750,
            'refund_amount' => 750,
            'reason' => 'Approved refund test case for admin review.',
            'status' => RefundStatus::Pending,
            'customer_preferred_method' => CustomerPreferredRefundMethod::Opm,
            'requested_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->post(route('refunds.approve', $refund), [
                'approved_refund_method' => ApprovedRefundMethod::Cashfree->value,
                'deduction_profile_key' => 'custom',
                'cancellation_charges' => 0,
                'gst_on_cancellation' => 0,
                'other_deduction' => 0,
                'refund_amount' => 750,
                'partial_difference_reason' => 'partial_refund',
                'review_notes' => 'Processed via payment gateway.',
            ])
            ->assertRedirect(route('refunds.show', $refund))
            ->assertSessionHas('status', 'refund-approved');

        $refund->refresh();

        $this->assertSame(RefundStatus::PendingExecution, $refund->status);
        $this->assertSame(ApprovedRefundMethod::Cashfree, $refund->approved_refund_method);
        $this->assertSame($admin->id, $refund->reviewed_by);
        $this->assertNotNull($refund->reviewed_at);
        $this->assertNull($refund->execution_transaction_id);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'refund.approved',
            'auditable_type' => $refund->getMorphClass(),
            'auditable_id' => $refund->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'refund.execution_started',
            'auditable_type' => $refund->getMorphClass(),
            'auditable_id' => $refund->id,
        ]);
    }

    public function test_ops_admin_can_complete_pending_execution_refund(): void
    {
        $ops = User::factory()->create();
        $ops->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = $this->createOrder($ops, paymentAmount: 1000);
        $incident = $this->createIncident($ops, $order);
        $refund = RefundRequest::query()->create([
            'order_id' => $order->id,
            'incident_id' => $incident->id,
            'reference_no' => 'REF-2026-000450',
            'amount' => 1000,
            'refund_amount' => 1000,
            'reason' => 'Execution workflow refund test case.',
            'status' => RefundStatus::PendingExecution,
            'approved_refund_method' => ApprovedRefundMethod::BankTransfer,
            'requested_by' => $agent->id,
            'reviewed_by' => $ops->id,
            'reviewed_at' => now(),
            'communication_channels' => [],
        ]);

        $this->actingAs($ops)
            ->post(route('refunds.complete', $refund), [
                'execution_reference_no' => 'UTR123456',
                'execution_transaction_id' => 'TXN-90001',
                'execution_remarks' => 'NEFT completed.',
            ])
            ->assertRedirect(route('refunds.show', $refund))
            ->assertSessionHas('status', 'refund-completed');

        $refund->refresh();

        $this->assertContains($refund->status, [RefundStatus::Completed, RefundStatus::Closed]);
        $this->assertSame('UTR123456', $refund->execution_reference_no);
        $this->assertSame('TXN-90001', $refund->execution_transaction_id);
        $this->assertSame($ops->id, $refund->executed_by);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'refund.completed',
            'auditable_type' => $refund->getMorphClass(),
            'auditable_id' => $refund->id,
        ]);

        $incident->refresh();
        $this->assertSame('closed', $incident->status->value);
    }

    public function test_admin_can_reject_pending_refund(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = $this->createOrder($admin);
        $refund = RefundRequest::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'REF-2026-000401',
            'amount' => 750,
            'reason' => 'Rejected refund test case for admin review.',
            'status' => RefundStatus::Pending,
            'requested_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->post(route('refunds.reject', $refund), [
                'review_notes' => 'Refund not eligible under current policy.',
            ])
            ->assertRedirect(route('refunds.show', $refund))
            ->assertSessionHas('status', 'refund-rejected');

        $refund->refresh();

        $this->assertSame(RefundStatus::Rejected, $refund->status);
        $this->assertNull($refund->refund_transaction_id);
        $this->assertSame('Refund not eligible under current policy.', $refund->reject_reason);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'refund.rejected',
            'auditable_type' => $refund->getMorphClass(),
            'auditable_id' => $refund->id,
        ]);
    }

    public function test_review_is_blocked_for_non_pending_refunds(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = $this->createOrder($admin, paymentAmount: 1000);
        $refund = RefundRequest::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'REF-2026-000402',
            'amount' => 750,
            'reason' => 'Already approved refund should not be reviewable.',
            'status' => RefundStatus::PendingExecution,
            'requested_by' => $admin->id,
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
            'approved_refund_method' => ApprovedRefundMethod::Upi,
        ]);

        $this->expectException(ValidationException::class);

        app(RefundRequestService::class)->approve(
            refund: $refund,
            user: $admin,
            data: [
                'approved_refund_method' => ApprovedRefundMethod::Cashfree->value,
                'refund_amount' => 750,
                'partial_difference_reason' => 'partial_refund',
            ],
            request: request(),
        );
    }

    public function test_superadmin_can_delete_refund_with_audit_log(): void
    {
        $superadmin = User::factory()->create();
        $superadmin->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $order = $this->createOrder($superadmin);
        $refund = RefundRequest::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'REF-2026-000500',
            'amount' => 200,
            'reason' => 'Delete test refund request for superadmin role.',
            'status' => RefundStatus::Pending,
            'requested_by' => $superadmin->id,
        ]);

        $this->actingAs($superadmin)
            ->delete(route('refunds.destroy', $refund))
            ->assertRedirect(route('refunds.index'))
            ->assertSessionHas('status', 'refund-deleted');

        $this->assertSoftDeleted('refund_requests', ['id' => $refund->id]);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'deleted',
            'auditable_type' => $refund->getMorphClass(),
            'auditable_id' => $refund->id,
        ]);
    }

    public function test_incident_must_belong_to_selected_order(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = $this->createOrder($agent, 'RD4000001');
        $otherOrder = $this->createOrder($agent, 'RD4000002');
        $incident = $this->createIncident($agent, $otherOrder);

        $this->actingAs($agent)
            ->post(route('refunds.store'), [
                'order_id' => $order->id,
                'incident_id' => $incident->id,
                'amount' => 100,
                'reason' => 'Invalid incident order mismatch validation test.',
                'customer_preferred_method' => CustomerPreferredRefundMethod::Opm->value,
            ])
            ->assertSessionHasErrors('incident_id');
    }

    public function test_dashboard_shows_refund_status_counts(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = $this->createOrder($user);

        RefundRequest::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'REF-2026-000601',
            'amount' => 100,
            'reason' => 'Pending refund for dashboard widget count test.',
            'status' => RefundStatus::Pending,
            'requested_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Refunds');
    }

    public function test_admin_dashboard_shows_all_refund_status_counts(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = $this->createOrder($admin);

        RefundRequest::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'REF-2026-000602',
            'amount' => 100,
            'reason' => 'Approved refund for dashboard widget count test.',
            'status' => RefundStatus::PendingExecution,
            'requested_by' => $admin->id,
        ]);

        RefundRequest::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'REF-2026-000603',
            'amount' => 100,
            'reason' => 'Rejected refund for dashboard widget count test.',
            'status' => RefundStatus::Rejected,
            'requested_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Refunds');

        $stats = app(\App\Services\DashboardService::class)->statsFor($admin);
        $this->assertSame(0, $stats['pending_refunds']);
        $this->assertSame(1, $stats['approved_refunds']);
        $this->assertSame(1, $stats['rejected_refunds']);
    }

    public function test_refund_amount_cannot_exceed_maximum_refundable_on_create(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = $this->createOrder($agent, paymentAmount: 1000);

        $this->actingAs($agent)
            ->post(route('refunds.store'), [
                'order_id' => $order->id,
                'amount' => 1500,
                'reason' => 'Attempting to refund more than the maximum refundable amount.',
                'customer_preferred_method' => CustomerPreferredRefundMethod::Opm->value,
            ])
            ->assertSessionHasErrors('refund_amount');
    }

    public function test_service_case_stays_open_when_customer_notification_fails(): void
    {
        $ops = User::factory()->create();
        $ops->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        $order = $this->createOrder($ops, paymentAmount: 1000);
        $order->update([
            'customer_phone' => '9999999999',
            'customer_email' => 'customer@example.com',
        ]);
        $incident = $this->createIncident($ops, $order);

        $refund = RefundRequest::query()->create([
            'order_id' => $order->id,
            'incident_id' => $incident->id,
            'reference_no' => 'REF-2026-000451',
            'amount' => 1000,
            'refund_amount' => 1000,
            'reason' => 'Customer notification failure should keep the service case open.',
            'status' => RefundStatus::PendingExecution,
            'approved_refund_method' => ApprovedRefundMethod::Cashfree,
            'requested_by' => $ops->id,
            'reviewed_by' => $ops->id,
            'reviewed_at' => now(),
            'communication_channels' => ['email', 'whatsapp'],
        ]);

        $this->actingAs($ops)
            ->post(route('refunds.complete', $refund), [
                'execution_reference_no' => 'UTR-FAIL-001',
                'execution_transaction_id' => 'TXN-FAIL-001',
            ])
            ->assertRedirect(route('refunds.show', $refund))
            ->assertSessionHas('status', 'refund-completed');

        $refund->refresh();
        $incident->refresh();

        $this->assertSame(RefundStatus::Completed, $refund->status);
        $this->assertSame('open', $incident->status->value);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'refund.customer_notified',
            'auditable_type' => $refund->getMorphClass(),
            'auditable_id' => $refund->id,
        ]);
    }

    public function test_standard_cancellation_profile_applies_configurable_deductions(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = $this->createOrder($admin, paymentAmount: 1180);
        $refund = RefundRequest::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'REF-2026-000700',
            'amount' => 1180,
            'refund_amount' => 1180,
            'reason' => 'Standard cancellation profile deduction engine test.',
            'status' => RefundStatus::Pending,
            'requested_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->post(route('refunds.approve', $refund), [
                'approved_refund_method' => ApprovedRefundMethod::Wallet->value,
                'deduction_profile_key' => 'standard_cancellation',
            ])
            ->assertRedirect(route('refunds.show', $refund));

        $refund->refresh();

        $this->assertSame(100.0, (float) $refund->cancellation_charges);
        $this->assertSame(18.0, (float) $refund->gst_on_cancellation);
        $this->assertSame(1062.0, (float) $refund->refund_amount);
        $this->assertSame(RefundStatus::PendingExecution, $refund->status);
    }
}
