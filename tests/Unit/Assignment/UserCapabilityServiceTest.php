<?php

namespace Tests\Unit\Assignment;

use App\Enums\Assignment\AssignmentCapability;
use App\Enums\TeamAvailabilityStatus;
use App\Models\User;
use App\Services\Operations\PresenceEngineService;
use App\Services\SettingService;
use App\Support\Assignment\AssignmentCapabilityResolver;
use App\Support\Assignment\Capabilities\UserCapabilityService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class UserCapabilityServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
    }

    public function test_support_agent_capability_uses_role_pool_when_no_explicit_grants(): void
    {
        $agent = $this->createAgent('agent@test.com');
        $this->createAgent('agent-b@test.com');

        $eligible = app(UserCapabilityService::class)->eligibleUsers(AssignmentCapability::SupportAgent);

        $this->assertCount(2, $eligible);
        $this->assertTrue($eligible->contains(fn (User $user): bool => $user->id === $agent->id));
    }

    public function test_explicit_capability_grant_overrides_role_pool(): void
    {
        $granted = $this->createAgent('granted@test.com');
        $this->createAgent('other@test.com');

        app(UserCapabilityService::class)->grant($granted, AssignmentCapability::SupportAgent);

        $eligible = app(UserCapabilityService::class)->eligibleUsers(AssignmentCapability::SupportAgent);

        $this->assertCount(1, $eligible);
        $this->assertSame($granted->id, $eligible->first()->id);
    }

    public function test_ready_queue_admin_falls_back_to_settings(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-18 10:00:00', 'Asia/Kolkata'));

        $dayAdmin = $this->createAdmin('day-admin@test.com');
        $nightAdmin = $this->createAdmin('night-admin@test.com');

        app(SettingService::class)->setMany([
            'assignment.timezone' => 'Asia/Kolkata',
            'assignment.day_shift_start' => '09:00',
            'assignment.day_shift_end' => '18:30',
            'assignment.day_shift_admin_user_id' => (string) $dayAdmin->id,
            'assignment.night_shift_admin_user_id' => (string) $nightAdmin->id,
            'assignment.ready_queue_day_admin_user_id' => (string) $dayAdmin->id,
            'assignment.ready_queue_night_admin_user_id' => (string) $nightAdmin->id,
        ]);

        $eligible = app(UserCapabilityService::class)->eligibleUsers(AssignmentCapability::ReadyQueueAdmin);

        $this->assertCount(1, $eligible);
        $this->assertSame($dayAdmin->id, $eligible->first()->id);

        Carbon::setTestNow();
    }

    public function test_capability_resolver_exposes_eligible_users(): void
    {
        $agent = $this->createAgent('resolver-agent@test.com');

        $eligible = app(AssignmentCapabilityResolver::class)
            ->eligibleUsers(AssignmentCapability::SupportAgent);

        $this->assertTrue($eligible->contains(fn (User $user): bool => $user->id === $agent->id));
    }

    private function createAgent(string $email): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'is_active' => true,
            'availability_status' => TeamAvailabilityStatus::Available,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);
        app(PresenceEngineService::class)->startSession($user);

        return $user->fresh();
    }

    private function createAdmin(string $email): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'is_active' => true,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $user;
    }
}
