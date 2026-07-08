<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnifiedIntakePrimaryFeatureFlagTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_dashboard_is_unchanged_when_unified_intake_primary_is_disabled(): void
    {
        config(['unified_intake.primary' => false]);

        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('New Service Request', false)
            ->assertSee('btn btn-primary btn-sm', false)
            ->assertDontSee('data-unified-intake-fallback', false)
            ->assertDontSee('class="unified-intake-primary"', false);
    }

    public function test_dashboard_promotes_global_search_when_unified_intake_primary_is_enabled(): void
    {
        config(['unified_intake.primary' => true]);

        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $response = $this->actingAs($agent)->get(route('dashboard'));

        $response->assertOk()
            ->assertSee('class="unified-intake-primary"', false)
            ->assertSee('id="global-search-input"', false)
            ->assertDontSee('New Service Request', false)
            ->assertDontSee('data-unified-intake-fallback', false)
            ->assertDontSee('data-bs-target="#quickCreateModal"', false);
    }

    public function test_unified_intake_primary_body_class_is_not_applied_off_dashboard(): void
    {
        config(['unified_intake.primary' => true]);

        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)
            ->get(route('incidents.index'))
            ->assertOk()
            ->assertDontSee('class="unified-intake-primary"', false);
    }
}
