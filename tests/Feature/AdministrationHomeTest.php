<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdministrationHomeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
    }

    private function createAdmin(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $user;
    }

    private function createSuperAdmin(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        return $user;
    }

    public function test_operations_admin_can_access_administration_home(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        $this->actingAs($user)
            ->get(route('admin.administration.index'))
            ->assertOk();
    }

    public function test_agent_cannot_access_administration_home(): void
    {
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)
            ->get(route('admin.administration.index'))
            ->assertForbidden();
    }

    public function test_admin_can_access_administration_home(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->get(route('admin.administration.index'))
            ->assertOk()
            ->assertSee('Administration')
            ->assertSee('aria-label="Administration workspace"', false)
            ->assertSee('Users')
            ->assertSee('Roles &amp; Access', false)
            ->assertSee('System Settings')
            ->assertSee('Holiday Calendar')
            ->assertSee('Integrations')
            ->assertDontSee('Application Settings')
            ->assertDontSee('Review system activity and record changes.', false);
    }

    public function test_superadmin_sees_application_settings_card(): void
    {
        $superadmin = $this->createSuperAdmin();

        $this->actingAs($superadmin)
            ->get(route('admin.administration.index'))
            ->assertOk()
            ->assertSee('Application Settings');
    }

    public function test_administration_home_cards_link_to_existing_routes(): void
    {
        $admin = $this->createAdmin();

        $response = $this->actingAs($admin)->get(route('admin.administration.index'));

        $response->assertOk();
        $response->assertSee(route('users.index'), false);
        $response->assertSee(route('admin.system-settings.index'), false);
        $response->assertSee(route('admin.workforce.holidays.index'), false);
    }

    public function test_existing_administration_pages_remain_accessible_for_admin(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)->get(route('users.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.system-settings.index'))->assertOk();
        $this->actingAs($admin)->get(route('audit-logs.index'))->assertOk();
    }

    public function test_existing_application_settings_remains_accessible_for_superadmin(): void
    {
        $superadmin = $this->createSuperAdmin();

        $this->actingAs($superadmin)->get(route('settings.index'))->assertOk();
    }

    public function test_integrations_placeholder_is_not_a_link(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->get(route('admin.administration.index'))
            ->assertOk()
            ->assertSee('Coming Soon')
            ->assertDontSee('href="'.route('admin.system-settings.index').'" title="Integrations"', false);
    }
}
