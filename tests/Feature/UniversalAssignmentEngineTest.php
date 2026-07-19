<?php

namespace Tests\Feature;

use App\Enums\Assignment\AssignmentCapability;
use App\Enums\Assignment\EmailAssignmentClassification;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\TeamAvailabilityStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\Assignment\UniversalAssignmentEngine;
use App\Services\Operations\PresenceEngineService;
use App\Services\SettingService;
use App\Support\Assignment\AssignmentCapabilityResolver;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class UniversalAssignmentEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'service_case_assignment.automation_grace_period_enabled' => false,
            'service_case_assignment.round_robin_enabled' => true,
        ]);

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        User::factory()->create([
            'name' => 'System',
            'email' => 'superadmin@radium.local',
        ]);
    }

    public function test_communication_intake_preserves_existing_ownership(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-18 10:00:00', 'Asia/Kolkata'));

        $dayAdmin = $this->createAdminUser('day-admin@test.com');
        $nightAdmin = $this->createAdminUser('night-admin@test.com');
        $this->configureAssignmentSettings($dayAdmin->id, $nightAdmin->id);

        $agent = $this->createEligibleAgent('agent@test.com');
        $incident = $this->createIncident($agent);

        $engine = app(UniversalAssignmentEngine::class);
        $systemUser = User::query()->where('email', 'superadmin@radium.local')->firstOrFail();

        $result = $engine->assignForCommunicationIntake($incident, $systemUser);

        $this->assertSame($agent->id, $result->assigned_to_user_id);
    }

    public function test_capability_resolver_maps_ready_queue_admin_to_shift_admin_settings(): void
    {
        $dayAdmin = $this->createAdminUser('day-admin@test.com');
        $nightAdmin = $this->createAdminUser('night-admin@test.com');
        $this->configureAssignmentSettings($dayAdmin->id, $nightAdmin->id);

        Carbon::setTestNow(Carbon::parse('2026-07-18 10:00:00', 'Asia/Kolkata'));

        $resolver = app(AssignmentCapabilityResolver::class);
        $assignee = $resolver->resolve(AssignmentCapability::ReadyQueueAdmin);

        $this->assertNotNull($assignee);
        $this->assertSame($dayAdmin->id, $assignee->id);
    }

    public function test_email_classification_existing_case_does_not_assign(): void
    {
        $incident = $this->createIncident();
        $systemUser = User::query()->where('email', 'superadmin@radium.local')->firstOrFail();

        $result = app(UniversalAssignmentEngine::class)->assignForEmailClassification(
            incident: $incident,
            actor: $systemUser,
            classification: EmailAssignmentClassification::ExistingCaseAttachOnly,
        );

        $this->assertNull($result->assigned_to_user_id);
    }

    public function test_shift_admin_fallback_remains_enabled_by_default(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-18 10:00:00', 'Asia/Kolkata'));

        $dayAdmin = $this->createAdminUser('day-admin@test.com');
        $nightAdmin = $this->createAdminUser('night-admin@test.com');
        $this->configureAssignmentSettings($dayAdmin->id, $nightAdmin->id);

        config(['universal_assignment.remove_shift_admin_fallback' => false]);

        $incident = $this->createIncident();
        $systemUser = User::query()->where('email', 'superadmin@radium.local')->firstOrFail();

        $result = app(UniversalAssignmentEngine::class)->assignForUnassignedIntake($incident, $systemUser);

        $this->assertSame($dayAdmin->id, $result->assigned_to_user_id);
    }

    public function test_shift_admin_fallback_can_be_disabled_via_feature_flag(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-18 10:00:00', 'Asia/Kolkata'));

        $dayAdmin = $this->createAdminUser('day-admin@test.com');
        $nightAdmin = $this->createAdminUser('night-admin@test.com');
        $this->configureAssignmentSettings($dayAdmin->id, $nightAdmin->id);

        config(['universal_assignment.remove_shift_admin_fallback' => true]);

        $incident = $this->createIncident();
        $systemUser = User::query()->where('email', 'superadmin@radium.local')->firstOrFail();

        $result = app(UniversalAssignmentEngine::class)->assignForUnassignedIntake($incident, $systemUser);

        $this->assertNull($result->assigned_to_user_id);
    }

    public function test_ready_queue_admin_capability_uses_dedicated_settings_when_configured(): void
    {
        $dayAdmin = $this->createAdminUser('day-admin@test.com');
        $nightAdmin = $this->createAdminUser('night-admin@test.com');
        $readyQueueAdmin = $this->createAdminUser('ready-admin@test.com');
        $this->configureAssignmentSettings($dayAdmin->id, $nightAdmin->id);

        app(SettingService::class)->setMany([
            'assignment.ready_queue_day_admin_user_id' => (string) $readyQueueAdmin->id,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-07-18 10:00:00', 'Asia/Kolkata'));

        $assignee = app(AssignmentCapabilityResolver::class)->resolve(AssignmentCapability::ReadyQueueAdmin);

        $this->assertNotNull($assignee);
        $this->assertSame($readyQueueAdmin->id, $assignee->id);
    }

    private function configureAssignmentSettings(int $dayAdminId, int $nightAdminId): void
    {
        app(SettingService::class)->setMany([
            'assignment.timezone' => 'Asia/Kolkata',
            'assignment.day_shift_start' => '09:00',
            'assignment.day_shift_end' => '18:30',
            'assignment.day_shift_admin_user_id' => (string) $dayAdminId,
            'assignment.night_shift_admin_user_id' => (string) $nightAdminId,
        ]);
    }

    private function createAdminUser(string $email): User
    {
        $user = User::factory()->create(['email' => $email, 'is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $user;
    }

    private function createEligibleAgent(string $email): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'is_active' => true,
            'availability_status' => TeamAvailabilityStatus::Available,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);
        app(PresenceEngineService::class)->startSession($user);

        return $user;
    }

    private function createIncident(?User $assignee = null): Incident
    {
        $actor = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RD-UAE-'.uniqid(),
            'serial_number' => 'SN-UAE-'.uniqid(),
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'REF-UAE-'.uniqid(),
            'category' => 'General',
            'source' => IncidentSource::Internal,
            'title' => 'UAE test case',
            'description' => '',
            'status' => IncidentStatus::Open,
            'assigned_to_user_id' => $assignee?->id,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
    }
}
