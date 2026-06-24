<?php

namespace Tests\Feature;

use App\Models\ApprovalNumber;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\ApprovalNumberService;
use App\Services\ApprovalReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ApprovalNumberTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function createIncident(User $user, Order $order, string $referenceNo): Incident
    {
        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => $referenceNo,
            'category' => 'Hardware',
            'source' => 'internal',
            'title' => 'Test incident',
            'description' => 'Test description',
            'status' => 'open',
            'created_by' => $user->id,
        ]);
    }

    private function createOrder(User $user, string $orderId = 'RD1000001'): Order
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

    public function test_agent_can_view_approvals_but_cannot_create_or_link(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $approval = ApprovalNumber::query()->create([
            'approval_number' => 'AP-2026-000001',
            'created_by' => $admin->id,
        ]);

        $this->actingAs($user)->get(route('approvals.index'))->assertOk();
        $this->actingAs($user)->get(route('approvals.show', $approval))->assertOk();
        $this->actingAs($user)->get(route('approvals.create'))->assertForbidden();
        $this->actingAs($user)->post(route('approvals.store'))->assertForbidden();
        $this->actingAs($user)->post(route('approvals.incidents.link', $approval), [
            'incident_ids' => [1],
        ])->assertForbidden();
    }

    public function test_admin_can_create_approval_with_auto_generated_number(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $response = $this->actingAs($admin)->post(route('approvals.store'), [
            'description' => 'Batch approval for Q1',
        ]);

        $approval = ApprovalNumber::query()->first();

        $this->assertNotNull($approval);
        $this->assertMatchesRegularExpression('/^AP-\d{4}-\d{6}$/', $approval->approval_number);
        $this->assertSame('Batch approval for Q1', $approval->description);
        $response->assertRedirect(route('approvals.show', $approval));

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'created',
            'auditable_type' => $approval->getMorphClass(),
            'auditable_id' => $approval->id,
            'user_id' => $admin->id,
        ]);
    }

    public function test_approval_reference_numbers_increment_per_year(): void
    {
        ApprovalNumber::query()->create([
            'approval_number' => 'AP-'.now()->format('Y').'-000005',
            'created_by' => User::factory()->create()->id,
        ]);

        $next = app(ApprovalReferenceService::class)->generate();

        $this->assertSame('AP-'.now()->format('Y').'-000006', $next);
    }

    public function test_index_supports_search_and_shows_linked_incident_count(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = $this->createOrder($admin);
        $incident = $this->createIncident($admin, $order, 'INC-2026-000001');

        $approval = ApprovalNumber::query()->create([
            'approval_number' => 'AP-2026-000099',
            'created_by' => $admin->id,
        ]);

        $approval->incidents()->attach($incident->id, ['linked_by' => $admin->id]);

        $this->actingAs($admin)
            ->get(route('approvals.index', ['approval_number' => '000099']))
            ->assertOk()
            ->assertSee('AP-2026-000099')
            ->assertSee('1 / 35')
            ->assertSee($admin->name);
    }

    public function test_admin_can_link_and_unlink_incidents_with_audit_logs(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = $this->createOrder($admin);
        $incident = $this->createIncident($admin, $order, 'INC-2026-000010');

        $approval = ApprovalNumber::query()->create([
            'approval_number' => 'AP-2026-000010',
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->post(route('approvals.incidents.link', $approval), [
                'incident_ids' => [$incident->id],
            ])
            ->assertRedirect(route('approvals.show', $approval))
            ->assertSessionHas('status', 'approval-incidents-linked');

        $this->assertTrue($approval->fresh()->incidents->contains($incident));

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'incident_linked',
            'auditable_type' => $approval->getMorphClass(),
            'auditable_id' => $approval->id,
        ]);

        $this->actingAs($admin)
            ->get(route('approvals.show', $approval))
            ->assertOk()
            ->assertSee('Linked Orders')
            ->assertSee($order->order_id)
            ->assertSee('1 / 35');

        $this->actingAs($admin)
            ->delete(route('approvals.incidents.unlink', [$approval, $incident]))
            ->assertRedirect(route('approvals.show', $approval))
            ->assertSessionHas('status', 'approval-incident-unlinked');

        $this->assertFalse($approval->fresh()->incidents->contains($incident));

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'incident_unlinked',
            'auditable_type' => $approval->getMorphClass(),
            'auditable_id' => $approval->id,
        ]);
    }

    public function test_linking_is_blocked_at_thirty_five_incidents(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $approval = ApprovalNumber::query()->create([
            'approval_number' => 'AP-2026-000020',
            'created_by' => $admin->id,
        ]);

        $order = $this->createOrder($admin);
        $incidentIds = [];

        for ($i = 1; $i <= 36; $i++) {
            $incidentIds[] = $this->createIncident(
                $admin,
                $order,
                sprintf('INC-2026-%06d', $i),
            )->id;
        }

        app(ApprovalNumberService::class)->linkIncidents(
            approval: $approval,
            incidentIds: array_slice($incidentIds, 0, 35),
            user: $admin,
            request: request(),
        );

        $this->expectException(ValidationException::class);

        app(ApprovalNumberService::class)->linkIncidents(
            approval: $approval,
            incidentIds: [end($incidentIds)],
            user: $admin,
            request: request(),
        );
    }

    public function test_superadmin_can_delete_approval_with_audit_log(): void
    {
        $superadmin = User::factory()->create();
        $superadmin->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $approval = ApprovalNumber::query()->create([
            'approval_number' => 'AP-2026-000030',
            'created_by' => $superadmin->id,
        ]);

        $this->actingAs($superadmin)
            ->delete(route('approvals.destroy', $approval))
            ->assertRedirect(route('approvals.index'))
            ->assertSessionHas('status', 'approval-deleted');

        $this->assertSoftDeleted('approval_numbers', ['id' => $approval->id]);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'deleted',
            'auditable_type' => $approval->getMorphClass(),
            'auditable_id' => $approval->id,
            'user_id' => $superadmin->id,
        ]);
    }

    public function test_admin_cannot_delete_approval(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $approval = ApprovalNumber::query()->create([
            'approval_number' => 'AP-2026-000031',
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->delete(route('approvals.destroy', $approval))
            ->assertForbidden();
    }

    public function test_incident_lookup_excludes_already_linked_incidents(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = $this->createOrder($admin);
        $linkedIncident = $this->createIncident($admin, $order, 'INC-2026-000050');
        $availableIncident = $this->createIncident($admin, $order, 'INC-2026-000051');

        $approval = ApprovalNumber::query()->create([
            'approval_number' => 'AP-2026-000050',
            'created_by' => $admin->id,
        ]);

        $approval->incidents()->attach($linkedIncident->id, ['linked_by' => $admin->id]);

        $response = $this->actingAs($admin)
            ->getJson(route('approvals.incidents.lookup', $approval).'?q=INC-2026-00005');

        $response->assertOk();
        $response->assertJsonFragment(['reference_no' => 'INC-2026-000051']);
        $response->assertJsonMissing(['reference_no' => 'INC-2026-000050']);
    }
}
