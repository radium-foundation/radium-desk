<?php

namespace Tests\Feature\Administration;

use App\Models\User;
use App\Support\Administration\PlatformConfigurationAccess;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SystemSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformConfigurationVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SystemSettingsSeeder::class);
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

    public function test_authorization_point_is_superadmin_only(): void
    {
        $admin = $this->createAdmin();
        $superadmin = $this->createSuperAdmin();

        $this->assertFalse(PlatformConfigurationAccess::canManage($admin));
        $this->assertTrue(PlatformConfigurationAccess::canManage($superadmin));
        $this->assertFalse(PlatformConfigurationAccess::canManage(null));
    }

    public function test_admin_cannot_see_platform_configuration_nav(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->get(route('admin.administration.index'))
            ->assertOk()
            ->assertSee('Operational Settings', false)
            ->assertDontSee('Platform Configuration', false)
            ->assertDontSee(route('admin.platform-configuration.index'), false);
    }

    public function test_admin_gets_403_on_platform_configuration_url(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->get(route('admin.platform-configuration.index'))
            ->assertForbidden();
    }

    public function test_admin_operational_settings_hides_platform_surfaces(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->get(route('admin.system-settings.index'))
            ->assertOk()
            ->assertSee('Operational Settings')
            ->assertSee('Operational Center')
            ->assertSee('Notifications')
            ->assertDontSee('Configuration Overview')
            ->assertDontSee('Cashfree')
            ->assertDontSee('Interakt')
            ->assertDontSee('Meta')
            ->assertDontSee('Version / build')
            ->assertDontSee('Open Platform monitoring')
            ->assertDontSee('Open Integration Health')
            ->assertDontSee('Audit History')
            ->assertDontSee('id="category-system"', false)
            ->assertDontSee('id="section-advanced"', false)
            ->assertDontSee('id="section-overview"', false);
    }

    public function test_superadmin_sees_platform_configuration_and_everything(): void
    {
        $superadmin = $this->createSuperAdmin();

        $this->actingAs($superadmin)
            ->get(route('admin.administration.index'))
            ->assertOk()
            ->assertSee('Operational Settings', false)
            ->assertSee('Platform Configuration', false)
            ->assertSee(route('admin.platform-configuration.index'), false);

        $this->actingAs($superadmin)
            ->get(route('admin.platform-configuration.index'))
            ->assertOk()
            ->assertSee('Platform Configuration')
            ->assertSee('Configuration Overview')
            ->assertSee('Cashfree')
            ->assertSee('Environment')
            ->assertSee('Audit History')
            ->assertSee('Advanced');
    }

    public function test_superadmin_operational_settings_remain_available(): void
    {
        $superadmin = $this->createSuperAdmin();

        $this->actingAs($superadmin)
            ->get(route('admin.system-settings.index'))
            ->assertOk()
            ->assertSee('Operational Settings')
            ->assertSee('Operational Center')
            ->assertDontSee('id="section-overview"', false);
    }

    public function test_existing_operational_settings_route_unchanged_for_admin(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->get(route('admin.system-settings.index'))
            ->assertOk();
    }
}
