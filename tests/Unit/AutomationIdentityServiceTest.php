<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\AutomationIdentityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutomationIdentityServiceTest extends TestCase
{
    use RefreshDatabase;

    private AutomationIdentityService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        config([
            'automation.display_name' => 'Ira',
            'automation.subtitle' => 'IRA AI',
            'cashfree.system_user_email' => 'superadmin@radium.local',
        ]);

        $this->service = app(AutomationIdentityService::class);
    }

    public function test_manual_user_displays_real_first_name(): void
    {
        $user = User::factory()->create([
            'name' => 'Ravi Kumar',
            'first_name' => 'Ravi',
            'email' => 'ravi@example.com',
        ]);

        $actor = $this->service->resolve($user);

        $this->assertSame('Ravi', $actor->displayName);
        $this->assertNull($actor->subtitle);
        $this->assertFalse($actor->isAutomation);
        $this->assertTrue($actor->isVisible());
    }

    public function test_system_user_displays_automation_identity(): void
    {
        $user = User::factory()->create([
            'name' => 'Super Admin',
            'first_name' => 'Super',
            'email' => 'superadmin@radium.local',
        ]);

        $actor = $this->service->resolve($user);

        $this->assertSame('Ira', $actor->displayName);
        $this->assertSame('IRA AI', $actor->subtitle);
        $this->assertTrue($actor->isAutomation);
    }

    public function test_null_user_is_treated_as_automation(): void
    {
        $actor = $this->service->resolve(null);

        $this->assertSame('Ira', $actor->displayName);
        $this->assertSame('IRA AI', $actor->subtitle);
        $this->assertTrue($actor->isAutomation);
    }

    public function test_role_actor_label_is_preserved_for_manual_users(): void
    {
        $user = User::factory()->create([
            'name' => 'Avinash Admin',
            'first_name' => 'Avinash',
            'email' => 'admin@example.com',
        ]);
        $user->assignRole(\Database\Seeders\RolePermissionSeeder::ROLE_ADMIN);

        $actor = $this->service->resolveWithRoleLabel($user);

        $this->assertSame('Operations Admin Avinash', $actor->displayName);
        $this->assertFalse($actor->isAutomation);
    }

    public function test_role_actor_label_returns_automation_identity_for_system_user(): void
    {
        $user = User::factory()->create([
            'name' => 'Super Admin',
            'first_name' => 'Super',
            'email' => 'superadmin@radium.local',
        ]);
        $user->assignRole(\Database\Seeders\RolePermissionSeeder::ROLE_SUPERADMIN);

        $actor = $this->service->resolveWithRoleLabel($user);

        $this->assertSame('Ira', $actor->displayName);
        $this->assertSame('IRA AI', $actor->subtitle);
        $this->assertTrue($actor->isAutomation);
    }

    public function test_system_user_returns_configured_user_from_database(): void
    {
        $user = User::factory()->create([
            'email' => 'superadmin@radium.local',
        ]);

        $this->assertSame($user->id, $this->service->systemUser()->id);
    }

    public function test_system_user_falls_back_to_first_user_when_configured_email_missing(): void
    {
        config(['cashfree.system_user_email' => 'missing@radium.local']);

        $user = User::factory()->create([
            'email' => 'other@radium.local',
        ]);

        $this->assertSame($user->id, $this->service->systemUser()->id);
    }
}
