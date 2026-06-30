<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\ServiceCaseActivityTimelineService;
use App\Services\ServiceCaseAssignmentService;
use App\Services\SettingService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimelineAutomationIdentityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        config([
            'automation.display_name' => 'Ira',
            'automation.subtitle' => 'AI Assistant',
            'cashfree.system_user_email' => 'superadmin@radium.local',
            'service_case_assignment.automation_grace_period_enabled' => true,
        ]);
    }

    public function test_cashfree_service_case_timeline_shows_automation_identity(): void
    {
        $systemUser = User::factory()->create([
            'email' => 'superadmin@radium.local',
            'name' => 'Super Admin',
            'first_name' => 'Super',
            'is_active' => true,
        ]);
        $systemUser->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $admin = User::factory()->create(['name' => 'Shipra Admin', 'first_name' => 'Shipra']);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $supportAgent = User::factory()->create(['name' => 'Support Agent', 'first_name' => 'Support']);
        $supportAgent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        app(SettingService::class)->setMany([
            'assignment.timezone' => 'Asia/Kolkata',
            'assignment.day_shift_start' => '09:00',
            'assignment.day_shift_end' => '18:30',
            'assignment.day_shift_admin_user_id' => (string) $admin->id,
            'assignment.night_shift_admin_user_id' => (string) $admin->id,
            'assignment.fallback_admin_1_user_id' => '',
            'assignment.fallback_admin_2_user_id' => '',
        ]);

        $order = Order::query()->create([
            'order_id' => 'ORD-CF-1',
            'status' => 'active',
            'created_by' => $systemUser->id,
            'updated_by' => $systemUser->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-CF-1',
            'category' => 'General',
            'source' => IncidentSource::Cashfree,
            'title' => 'Cashfree payment — ORD-CF-1',
            'description' => 'Automatically created from Cashfree payment webhook.',
            'status' => IncidentStatus::AwaitingProductDetails,
            'created_by' => $systemUser->id,
            'updated_by' => $systemUser->id,
        ]);

        $incident = app(ServiceCaseAssignmentService::class)->assignOnCreate($incident->fresh(), $systemUser);

        $timeline = app(ServiceCaseActivityTimelineService::class)->forIncident($incident->fresh());

        $automationEntries = $timeline->filter(fn ($entry) => $entry->actor->isAutomation);

        $this->assertGreaterThanOrEqual(2, $automationEntries->count());

        foreach ($automationEntries as $entry) {
            $this->assertSame('Ira', $entry->actor->displayName);
            $this->assertSame('AI Assistant', $entry->actor->subtitle);
        }

        $this->assertNull($incident->assigned_to_user_id);
        $this->assertNotNull($incident->automation_pending_until);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'service_case.automation_pending',
            'auditable_id' => $incident->id,
        ]);
    }

    public function test_manual_service_case_timeline_preserves_user_names(): void
    {
        $agent = User::factory()->create(['name' => 'Ravi Agent', 'first_name' => 'Ravi']);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $admin = User::factory()->create(['name' => 'Shipra Admin', 'first_name' => 'Shipra']);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        app(SettingService::class)->setMany([
            'assignment.timezone' => 'Asia/Kolkata',
            'assignment.day_shift_start' => '09:00',
            'assignment.day_shift_end' => '18:30',
            'assignment.day_shift_admin_user_id' => (string) $admin->id,
            'assignment.night_shift_admin_user_id' => (string) $admin->id,
            'assignment.fallback_admin_1_user_id' => '',
            'assignment.fallback_admin_2_user_id' => '',
        ]);

        $order = Order::query()->create([
            'order_id' => 'ORD-MAN-1',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-MAN-1',
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Scanner issue',
            'description' => 'Customer report',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);

        app(ServiceCaseAssignmentService::class)->assignOnCreate($incident->fresh(), $agent);

        $timeline = app(ServiceCaseActivityTimelineService::class)->forIncident($incident->fresh());

        foreach ($timeline as $entry) {
            if ($entry->title === 'Automation Pending') {
                continue;
            }

            $this->assertFalse($entry->actor->isAutomation);
            $this->assertSame('Ravi', $entry->actor->displayName);
        }
    }

    public function test_cashfree_service_case_page_renders_automation_identity_in_html(): void
    {
        $systemUser = User::factory()->create([
            'email' => 'superadmin@radium.local',
            'name' => 'Super Admin',
            'first_name' => 'Super',
            'is_active' => true,
        ]);
        $systemUser->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $admin = User::factory()->create(['name' => 'Shipra Admin', 'first_name' => 'Shipra']);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $supportAgent = User::factory()->create(['name' => 'Support Agent', 'first_name' => 'Support']);
        $supportAgent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        app(SettingService::class)->setMany([
            'assignment.timezone' => 'Asia/Kolkata',
            'assignment.day_shift_start' => '09:00',
            'assignment.day_shift_end' => '18:30',
            'assignment.day_shift_admin_user_id' => (string) $admin->id,
            'assignment.night_shift_admin_user_id' => (string) $admin->id,
            'assignment.fallback_admin_1_user_id' => '',
            'assignment.fallback_admin_2_user_id' => '',
        ]);

        $order = Order::query()->create([
            'order_id' => 'ORD-CF-HTML',
            'status' => 'active',
            'created_by' => $systemUser->id,
            'updated_by' => $systemUser->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-CF-HTML',
            'category' => 'General',
            'source' => IncidentSource::Cashfree,
            'title' => 'Cashfree payment',
            'description' => 'Webhook',
            'status' => IncidentStatus::AwaitingProductDetails,
            'created_by' => $systemUser->id,
            'updated_by' => $systemUser->id,
        ]);

        app(ServiceCaseAssignmentService::class)->assignOnCreate($incident->fresh(), $systemUser);

        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)
            ->get(route('incidents.show', $incident))
            ->assertOk()
            ->assertSee('Ira', false)
            ->assertSee('AI Assistant', false)
            ->assertSee('timeline-actor-subtitle', false);
    }
}
