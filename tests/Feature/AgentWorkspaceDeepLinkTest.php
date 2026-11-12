<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentWorkspaceDeepLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
    }

    public function test_legacy_filter_my_attention_canonicalizes_to_workspace(): void
    {
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)
            ->get(route('dashboard', ['filter' => 'my_attention']))
            ->assertRedirect(route('dashboard', ['workspace' => 'my_attention']));
    }

    public function test_workspace_my_attention_renders_without_redirect_loop(): void
    {
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)
            ->get(route('dashboard', ['workspace' => 'my_attention']))
            ->assertOk()
            ->assertSee('data-live-workspace="my_attention"', false)
            ->assertSee('data-live-filter="my_attention"', false);
    }

    public function test_legacy_queue_my_work_canonicalizes_to_workspace(): void
    {
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)
            ->get(route('dashboard', ['queue' => 'my_work']))
            ->assertRedirect(route('dashboard', ['workspace' => 'my_work']));
    }

    public function test_agent_action_cards_emit_workspace_deep_links(): void
    {
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $html = $this->actingAs($agent)
            ->get(route('dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-workspace="my_work"', $html);
        $this->assertStringContainsString('data-workspace="my_attention"', $html);
    }

    public function test_admin_legacy_queue_still_resolves_via_workspace_redirect(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->actingAs($admin)
            ->get(route('dashboard', ['queue' => 'attention']))
            ->assertRedirect(route('dashboard', ['workspace' => 'attention']));
    }

    public function test_guest_is_redirected_from_dashboard(): void
    {
        $this->get(route('dashboard'))
            ->assertRedirect(route('login'));
    }
}
