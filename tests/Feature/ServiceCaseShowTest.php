<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\Remark;
use App\Models\User;
use App\Services\ServiceCaseActivityTimelineService;
use App\Services\ServiceCaseAssignmentService;
use App\Services\SettingService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceCaseShowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
    }

    private function createAdminUser(string $email, string $name): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => $email,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $user;
    }

    private function configureAssignmentSettings(int $adminId): void
    {
        app(SettingService::class)->setMany([
            'assignment.timezone' => 'Asia/Kolkata',
            'assignment.day_shift_start' => '09:00',
            'assignment.day_shift_end' => '18:30',
            'assignment.day_shift_admin_user_id' => (string) $adminId,
            'assignment.night_shift_admin_user_id' => (string) $adminId,
            'assignment.fallback_admin_1_user_id' => '',
            'assignment.fallback_admin_2_user_id' => '',
        ]);
    }

    private function createIncident(User $creator, Order $order, array $overrides = []): Incident
    {
        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => $overrides['reference_no'] ?? 'SC-00001',
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => $overrides['title'] ?? 'Scanner not detecting',
            'description' => $overrides['description'] ?? 'Customer reports intermittent failures.',
            'status' => $overrides['status'] ?? IncidentStatus::Open,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
            ...$overrides,
        ]);
    }

    public function test_show_page_renders_new_layout_sections(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'ORD-SHOW-1',
            'serial_number' => 'SN-SHOW-1',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Jane Doe',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = $this->createIncident($agent, $order, [
            'reference_no' => 'SC-SHOW-1',
            'title' => 'Fingerprint Scanner Not Detecting',
        ]);

        $this->actingAs($agent)
            ->get(route('incidents.show', $incident))
            ->assertOk()
            ->assertSee('data-service-case-show', false)
            ->assertSee('Fingerprint Scanner Not Detecting', false)
            ->assertSee('Customer reports intermittent failures.', false)
            ->assertSee('Jane Doe', false)
            ->assertSee('ORD-SHOW-1', false)
            ->assertSee('Activity Timeline', false)
            ->assertSee('Quick Actions', false)
            ->assertSee('Service Case History', false)
            ->assertSee(config('ui.service_case.shortcuts_hint'), false)
            ->assertSee('id="activity-timeline"', false)
            ->assertSee('data-sticky-bar', false);
    }

    public function test_unified_timeline_is_chronological_and_includes_events(): void
    {
        $admin = $this->createAdminUser('admin@example.com', 'Avinash Admin');
        $this->configureAssignmentSettings($admin->id);

        $agent = User::factory()->create(['name' => 'Ravi Agent']);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'ORD-TL-1',
            'serial_number' => 'SN-TL-1',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = $this->createIncident($agent, $order, [
            'reference_no' => 'SC-TL-1',
            'status' => IncidentStatus::Open,
        ]);

        app(ServiceCaseAssignmentService::class)->assignOnCreate($incident->fresh(), $agent);

        Remark::query()->create([
            'user_id' => $agent->id,
            'remarkable_type' => $incident->getMorphClass(),
            'remarkable_id' => $incident->id,
            'body' => 'Customer called. Remote support started.',
            'created_at' => now()->addMinutes(10),
            'updated_at' => now()->addMinutes(10),
        ]);

        $this->actingAs($admin)
            ->patch(route('incidents.status.update', $incident), ['status' => 'in_progress'])
            ->assertRedirect(route('incidents.show', $incident).'#activity-timeline');

        $timeline = app(ServiceCaseActivityTimelineService::class)->forIncident($incident->fresh());

        $this->assertGreaterThanOrEqual(3, $timeline->count());
        $this->assertSame('Created Service Case', $timeline->first()->title);

        $titles = $timeline->pluck('title')->filter()->values()->all();
        $this->assertContains('Created Service Case', $titles);

        $remarkEntry = $timeline->first(fn ($entry) => $entry->body === 'Customer called. Remote support started.');
        $this->assertNotNull($remarkEntry);

        $statusEntry = $timeline->first(fn ($entry) => str_contains($entry->title, 'In Progress'));
        $this->assertNotNull($statusEntry);

        $timestamps = $timeline->pluck('occurredAt')->values();
        $sorted = $timestamps->sortBy(fn ($date) => $date->timestamp)->values();
        $this->assertTrue($timestamps->every(fn ($date, $index) => $date->timestamp === $sorted[$index]->timestamp));
    }

    public function test_resolve_and_close_status_actions_work(): void
    {
        $admin = $this->createAdminUser('admin@example.com', 'Admin User');

        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'ORD-STATUS-1',
            'serial_number' => 'SN-STATUS-1',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = $this->createIncident($agent, $order, [
            'reference_no' => 'SC-STATUS-1',
            'status' => IncidentStatus::InProgress,
        ]);

        $this->actingAs($admin)
            ->patch(route('incidents.status.update', $incident), [
                'status' => 'resolved',
                'body' => 'Resolved during status workflow test.',
            ])
            ->assertRedirect(route('incidents.show', $incident).'#activity-timeline')
            ->assertSessionHas('status', 'service-case-resolved');

        $incident->refresh();
        $this->assertSame(IncidentStatus::Resolved, $incident->status);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'event' => 'service_case.status_changed',
        ]);

        $this->actingAs($admin)
            ->patch(route('incidents.status.update', $incident), [
                'status' => 'closed',
                'body' => 'Closed during status workflow test.',
            ])
            ->assertSessionHas('status', 'service-case-closed');

        $this->assertSame(IncidentStatus::Closed, $incident->fresh()->status);
    }

    public function test_closed_service_case_cannot_be_reopened(): void
    {
        $admin = $this->createAdminUser('admin@example.com', 'Admin User');

        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'ORD-REOPEN-1',
            'serial_number' => 'SN-REOPEN-1',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = $this->createIncident($agent, $order, [
            'reference_no' => 'SC-REOPEN-1',
            'status' => IncidentStatus::Closed,
        ]);

        $this->actingAs($admin)
            ->patch(route('incidents.status.update', $incident), ['status' => 'open'])
            ->assertSessionHasErrors('status');

        $this->assertSame(IncidentStatus::Closed, $incident->fresh()->status);
    }

    public function test_user_without_update_permission_cannot_change_status(): void
    {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('incidents.view');

        $order = Order::query()->create([
            'order_id' => 'ORD-PERM-1',
            'serial_number' => 'SN-PERM-1',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $viewer->id,
        ]);

        $incident = $this->createIncident($viewer, $order, ['reference_no' => 'SC-PERM-1']);

        $this->actingAs($viewer)
            ->patch(route('incidents.status.update', $incident), ['status' => 'closed'])
            ->assertForbidden();
    }

    public function test_empty_related_sections_are_hidden(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'ORD-EMPTY-1',
            'serial_number' => 'SN-EMPTY-1',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = $this->createIncident($agent, $order, ['reference_no' => 'SC-EMPTY-1']);

        $response = $this->actingAs($agent)->get(route('incidents.show', $incident));

        $response->assertOk()
            ->assertSee('Related Information', false)
            ->assertDontSee('No approval numbers linked', false)
            ->assertDontSee('No refund requests linked', false);
    }

    public function test_service_case_history_shows_sibling_cases_and_highlights_current(): void
    {
        $admin = $this->createAdminUser('admin@example.com', 'Admin User');

        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'ORD-HIST-1',
            'serial_number' => 'SN-HIST-1',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $older = $this->createIncident($agent, $order, [
            'reference_no' => 'SC-HIST-OLD',
            'title' => 'Older issue',
            'status' => IncidentStatus::Closed,
            'assigned_to_user_id' => $admin->id,
            'created_at' => now()->subDay(),
        ]);

        $current = $this->createIncident($agent, $order, [
            'reference_no' => 'SC-HIST-CUR',
            'title' => 'Current issue',
            'status' => IncidentStatus::Open,
            'assigned_to_user_id' => $admin->id,
        ]);

        $response = $this->actingAs($agent)->get(route('incidents.show', $current));

        $response->assertOk()
            ->assertSee('SC-HIST-OLD', false)
            ->assertSee('SC-HIST-CUR', false)
            ->assertSee('Older issue', false)
            ->assertSee('Current issue', false)
            ->assertSee('table-active', false);
    }

    public function test_remark_redirect_uses_activity_timeline_fragment(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'ORD-RM-1',
            'serial_number' => 'SN-RM-1',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = $this->createIncident($agent, $order, ['reference_no' => 'SC-RM-1']);

        $this->actingAs($agent)
            ->from(route('incidents.show', $incident))
            ->post(route('remarks.store'), [
                'remarkable_type' => Incident::class,
                'remarkable_id' => $incident->id,
                'body' => 'Follow-up note for customer.',
            ])
            ->assertRedirect(route('incidents.show', $incident).'#activity-timeline');
    }
}
