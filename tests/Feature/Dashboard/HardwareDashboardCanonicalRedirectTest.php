<?php

namespace Tests\Feature\Dashboard;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HardwareDashboardCanonicalRedirectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
        $this->withoutVite();
    }

    public function test_legacy_queue_hardware_redirects_to_canonical_workspace_param(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->actingAs($admin)
            ->get(route('dashboard', ['queue' => 'hardware']))
            ->assertRedirect(route('dashboard', ['workspace' => 'hardware']));
    }

    public function test_canonical_workspace_hardware_renders_deferred_shell(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $html = $this->actingAs($admin)
            ->get(route('dashboard', ['workspace' => 'hardware']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-hardware-defer-rows="1"', $html);
        $this->assertStringContainsString('data-hardware-progressive="1"', $html);
    }
}
