<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardActivityFeedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_activity_refresh_returns_feed_html_for_authorized_users(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        [$incident] = $this->createIncident($admin, [
            'customer_name' => 'Activity Customer',
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
            ->getJson(route('dashboard.activity'));

        $response->assertOk()
            ->assertJsonPath('empty', false)
            ->assertJsonStructure(['html']);

        $html = (string) $response->json('html');

        $this->assertStringContainsString('My Activity', $html);
        $this->assertStringContainsString('Activity Customer', $html);
    }

    public function test_activity_refresh_returns_empty_payload_without_permission(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($user)
            ->getJson(route('dashboard.activity'))
            ->assertOk()
            ->assertExactJson([
                'html' => null,
                'empty' => true,
            ]);
    }

    public function test_dashboard_page_includes_activity_refresh_attributes(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        [$incident] = $this->createIncident($admin, [
            'customer_name' => 'Desk Customer',
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

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-activity-refresh-url', false)
            ->assertSee('data-activity-poll-interval-ms', false)
            ->assertSee(route('dashboard.activity'), false);
    }

    /**
     * @param  array{customer_name?: string}  $orderOverrides
     * @return array{0: Incident, 1: Order}
     */
    private function createIncident(User $user, array $orderOverrides = []): array
    {
        $order = Order::query()->create([
            'order_id' => 'RD1000099',
            'serial_number' => 'SN-0099',
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
            'title' => 'Activity feed test case',
            'description' => 'Activity feed test case.',
            'status' => 'open',
            'created_by' => $user->id,
        ]);

        return [$incident, $order];
    }
}
