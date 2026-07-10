<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Operations\SmartAssignmentService;
use App\Services\ServiceCaseAssignmentService;
use App\Services\SettingService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ManualAssignmentGovernanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        config([
            'cashfree.system_user_email' => 'superadmin@radium.local',
            'service_case_assignment.manual_assign_excluded_emails' => ['demo@radiumbox.com'],
        ]);
    }

    public function test_manual_assign_dropdown_includes_admin_users(): void
    {
        $actor = $this->createAgent('actor@test.com', 'Actor Agent');
        $avinash = $this->createAdmin('avinash@radiumbox.com', 'Avinash Kumar');
        $dileep = $this->createAdmin('dileep@radiumbox.com', 'Dileep Kumar');

        $this->actingAs($actor);

        $ids = collect(app(ServiceCaseAssignmentService::class)->reassignableUsers($actor))
            ->pluck('id')
            ->all();

        $this->assertContains($avinash->id, $ids);
        $this->assertContains($dileep->id, $ids);
    }

    public function test_manual_assign_dropdown_includes_escalation_specialist(): void
    {
        $actor = $this->createAdmin('actor@test.com', 'Actor Admin');
        $specialist = $this->createEscalationSpecialist('shubhanshi@radiumbox.com', 'Shubhanshi');

        $this->actingAs($actor);

        $ids = collect(app(ServiceCaseAssignmentService::class)->reassignableUsers($actor))
            ->pluck('id')
            ->all();

        $this->assertContains($specialist->id, $ids);
    }

    public function test_manual_assign_dropdown_hides_superadmin(): void
    {
        $actor = $this->createAgent('actor@test.com', 'Actor Agent');
        $owner = User::factory()->create([
            'name' => 'Ravi Owner',
            'email' => 'ravi@radiumbox.com',
            'is_active' => true,
        ]);
        $owner->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $this->actingAs($actor);

        $ids = collect(app(ServiceCaseAssignmentService::class)->reassignableUsers($actor))
            ->pluck('id')
            ->all();

        $this->assertNotContains($owner->id, $ids);
    }

    public function test_manual_assign_dropdown_hides_current_user(): void
    {
        $actor = $this->createAgent('actor@test.com', 'Actor Agent');
        $peer = $this->createAgent('peer@test.com', 'Peer Agent');

        $this->actingAs($actor);

        $ids = collect(app(ServiceCaseAssignmentService::class)->reassignableUsers($actor))
            ->pluck('id')
            ->all();

        $this->assertContains($peer->id, $ids);
        $this->assertNotContains($actor->id, $ids);
    }

    public function test_manual_assign_dropdown_hides_demo_and_system_users(): void
    {
        $actor = $this->createAgent('actor@test.com', 'Actor Agent');

        $demo = User::factory()->create([
            'name' => 'Demo Agent',
            'email' => 'demo@radiumbox.com',
            'is_active' => true,
        ]);
        $demo->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $systemUser = User::factory()->create([
            'name' => 'System',
            'email' => 'superadmin@radium.local',
            'is_active' => true,
        ]);
        $systemUser->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($actor);

        $ids = collect(app(ServiceCaseAssignmentService::class)->reassignableUsers($actor))
            ->pluck('id')
            ->all();

        $this->assertNotContains($demo->id, $ids);
        $this->assertNotContains($systemUser->id, $ids);
    }

    public function test_auto_assignment_never_selects_admin_or_escalation_specialist(): void
    {
        $this->createAdmin('admin@test.com', 'Shift Admin');
        $this->createEscalationSpecialist('escalation@test.com', 'Escalation Specialist');

        $pool = app(ServiceCaseAssignmentService::class)->activeSupportAgents();
        $poolIds = collect($pool)->pluck('id')->all();

        $this->assertEmpty($poolIds);

        $candidates = app(SmartAssignmentService::class)->eligibleCandidates();
        $candidateIds = collect($candidates)->pluck('id')->all();

        $this->assertEmpty($candidateIds);
    }

    public function test_shift_admin_fallback_skips_superadmin(): void
    {
        $superadmin = User::factory()->create([
            'name' => 'Ravi Owner',
            'email' => 'info@radiumbox.com',
            'is_active' => true,
        ]);
        $superadmin->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $fallbackAdmin = $this->createAdmin('dileep@radiumbox.com', 'Dileep Admin');

        app(SettingService::class)->setMany([
            'assignment.timezone' => 'Asia/Kolkata',
            'assignment.day_shift_start' => '09:00',
            'assignment.day_shift_end' => '18:30',
            'assignment.day_shift_admin_user_id' => (string) $superadmin->id,
            'assignment.night_shift_admin_user_id' => (string) $superadmin->id,
            'assignment.fallback_admin_1_user_id' => (string) $fallbackAdmin->id,
            'assignment.fallback_admin_2_user_id' => '',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-06-24 14:00:00', 'Asia/Kolkata'));

        $assignee = app(ServiceCaseAssignmentService::class)->resolveAssignee();

        $this->assertTrue($assignee->is($fallbackAdmin));

        Carbon::setTestNow();
    }

    private function createAdmin(string $email, string $name): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => $email,
            'is_active' => true,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $user;
    }

    private function createAgent(string $email, string $name): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => $email,
            'is_active' => true,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        return $user;
    }

    private function createEscalationSpecialist(string $email, string $name): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => $email,
            'is_active' => true,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_ESCALATION_SPECIALIST);

        return $user;
    }
}
