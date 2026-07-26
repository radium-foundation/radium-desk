<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\TeamAvailabilityStatus;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Models\WorkSession;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTeamActivityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config(['dashboard-team-activity.enabled' => true]);
    }

    public function test_team_activity_refresh_returns_panel_html_for_authorized_users(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);
        $admin->update(['availability_status' => TeamAvailabilityStatus::Available]);

        WorkSession::query()->create([
            'user_id' => $admin->id,
            'work_date' => now()->toDateString(),
            'login_at' => now()->subHours(2),
            'active_duration_seconds' => 7200,
        ]);

        [$incident] = $this->createIncident($admin, [
            'customer_name' => 'Team Customer',
        ]);

        AuditLog::query()->create([
            'user_id' => $admin->id,
            'event' => 'service_case.assigned',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'new_values' => [
                'assigned_to_user_id' => $admin->id,
            ],
        ]);

        $response = $this->actingAs($admin)
            ->getJson(route('dashboard.team-activity'));

        $response->assertOk()
            ->assertJsonPath('empty', false)
            ->assertJsonStructure(['html', 'generated_at', 'agent_count']);

        $html = (string) $response->json('html');

        $this->assertStringContainsString('Team Activity', $html);
        $this->assertStringContainsString('data-team-activity-refresh-url', $html);
        $this->assertStringContainsString((string) $admin->name, $html);
        $this->assertStringContainsString('Assigned', $html);
        $this->assertStringContainsString('Today ·', $html);
        $this->assertMatchesRegularExpression('/Today · \d+ activit(?:y|ies)/', $html);
        $this->assertStringContainsString('Active', $html);
        $this->assertStringNotContainsString('team-activity-status-dot--', $html);
    }

    public function test_team_activity_refresh_returns_empty_payload_without_permission(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($user)
            ->getJson(route('dashboard.team-activity'))
            ->assertOk()
            ->assertJsonPath('empty', true)
            ->assertJsonPath('html', null);
    }

    public function test_dashboard_page_includes_team_activity_attributes(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);
        $admin->update(['availability_status' => TeamAvailabilityStatus::Available]);

        WorkSession::query()->create([
            'user_id' => $admin->id,
            'work_date' => now()->toDateString(),
            'login_at' => now()->subHour(),
            'active_duration_seconds' => 3600,
        ]);

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-team-activity-refresh-url', false)
            ->assertSee('data-team-activity-poll-interval-ms', false)
            ->assertSee(route('dashboard.team-activity'), false)
            ->assertSee('Team Activity', false)
            ->assertDontSee('data-activity-refresh-url', false);
    }

    public function test_expanded_agent_history_is_included_in_refresh_payload(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);
        $admin->update(['availability_status' => TeamAvailabilityStatus::Available]);

        WorkSession::query()->create([
            'user_id' => $admin->id,
            'work_date' => now()->toDateString(),
            'login_at' => now()->subHours(3),
            'active_duration_seconds' => 10800,
        ]);

        [$incident] = $this->createIncident($admin);

        foreach (['service_case.assigned', 'service_case.status_changed', 'service_case.escalated'] as $event) {
            AuditLog::query()->create([
                'user_id' => $admin->id,
                'event' => $event,
                'auditable_type' => $incident->getMorphClass(),
                'auditable_id' => $incident->id,
                'new_values' => [],
            ]);
        }

        $html = (string) $this->actingAs($admin)
            ->getJson(route('dashboard.team-activity', [
                'expanded' => [$admin->id],
            ]))
            ->assertOk()
            ->json('html');

        $this->assertStringContainsString('team-activity-history', $html);
        $this->assertStringContainsString('is-expanded', $html);
    }

    /**
     * @param  array{customer_name?: string}  $orderOverrides
     * @return array{0: Incident, 1: Order}
     */
    private function createIncident(User $user, array $orderOverrides = []): array
    {
        $order = Order::query()->create([
            'order_id' => 'RD1000100',
            'serial_number' => 'SN-0100',
            'customer_name' => $orderOverrides['customer_name'] ?? 'Test Customer',
            'product_name' => 'RBX 110',
            'device_model' => 'RBX 110',
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Team activity feed test case',
            'description' => 'Team activity feed test case.',
            'status' => 'open',
            'created_by' => $user->id,
        ]);

        return [$incident, $order];
    }
}
