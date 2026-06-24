<?php

namespace Tests\Feature;

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

    private function createOrder(User $user, string $orderId = 'RD2000001'): Order
    {
        return Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => 'SN-'.$orderId,
            'product_name' => 'Radium Device',
            'device_model' => 'Model X',
            'status' => 'active',
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
            'refund_transaction_id' => 'RFTX-001',
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
        ]);

        $refund = RefundRequest::query()->first();

        $this->assertNotNull($refund);
        $this->assertMatchesRegularExpression('/^REF-\d{4}-\d{6}$/', $refund->reference_no);
        $this->assertSame(RefundStatus::Pending, $refund->status);
        $this->assertSame($incident->id, $refund->incident_id);
        $response->assertRedirect(route('refunds.show', $refund));

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'created',
            'auditable_type' => $refund->getMorphClass(),
            'auditable_id' => $refund->id,
            'user_id' => $agent->id,
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

    public function test_admin_can_approve_pending_refund(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = $this->createOrder($admin);
        $refund = RefundRequest::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'REF-2026-000400',
            'amount' => 750,
            'reason' => 'Approved refund test case for admin review.',
            'status' => RefundStatus::Pending,
            'requested_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->post(route('refunds.approve', $refund), [
                'refund_transaction_id' => 'RFTX-90001',
                'review_notes' => 'Processed via payment gateway.',
            ])
            ->assertRedirect(route('refunds.show', $refund))
            ->assertSessionHas('status', 'refund-approved');

        $refund->refresh();

        $this->assertSame(RefundStatus::Approved, $refund->status);
        $this->assertSame('RFTX-90001', $refund->refund_transaction_id);
        $this->assertSame($admin->id, $refund->reviewed_by);
        $this->assertNotNull($refund->reviewed_at);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'approved',
            'auditable_type' => $refund->getMorphClass(),
            'auditable_id' => $refund->id,
        ]);
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

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'rejected',
            'auditable_type' => $refund->getMorphClass(),
            'auditable_id' => $refund->id,
        ]);
    }

    public function test_review_is_blocked_for_non_pending_refunds(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = $this->createOrder($admin);
        $refund = RefundRequest::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'REF-2026-000402',
            'amount' => 750,
            'reason' => 'Already approved refund should not be reviewable.',
            'status' => RefundStatus::Approved,
            'requested_by' => $admin->id,
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
        ]);

        $this->expectException(ValidationException::class);

        app(RefundRequestService::class)->approve(
            refund: $refund,
            user: $admin,
            reviewNotes: null,
            refundTransactionId: 'RFTX-999',
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
            ->assertSee('Pending Refunds');
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
            'status' => RefundStatus::Approved,
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
            ->assertSee('Approved Refunds')
            ->assertSee('Rejected Refunds');
    }
}
