<?php

namespace Tests\Feature\Dashboard;

use App\Enums\HardwareFulfilmentState;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Dashboard\Concerns\CreatesHardwareDashboardFulfilments;
use Tests\TestCase;

class HardwareDashboardProgressiveLoadingTest extends TestCase
{
    use CreatesHardwareDashboardFulfilments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
        $this->withoutVite();
    }

    public function test_shell_uses_compact_skeleton_row_count(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $this->seedCatalog();

        $html = $this->actingAs($admin)
            ->followingRedirects()
            ->get(route('dashboard', ['workspace' => 'hardware']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-hardware-progressive="1"', $html);
        $this->assertStringContainsString('data-hardware-initial-per-page="10"', $html);
        $this->assertMatchesRegularExpression(
            '/id="dashboard-hardware-workspace"[^>]*data-hardware-initial-per-page="10"/',
            $html,
        );
        if (preg_match('/id="dashboard-hardware-workspace"[\s\S]*?(?=<div class="dashboard-hardware-page-loading")/', $html, $workspaceSection) === 1) {
            $this->assertSame(
                10,
                substr_count($workspaceSection[0], 'dashboard-hardware-skeleton-row'),
                'Expected compact skeleton rows on deferred shell',
            );
        }
    }

    public function test_progressive_first_page_returns_ten_rows(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $this->seedCatalog();

        for ($i = 1; $i <= 25; $i++) {
            $this->fulfilment(sprintf('RDE982%03d', $i), HardwareFulfilmentState::ReadyForFulfilment);
        }

        $response = $this->actingAs($admin)
            ->getJson(route('dashboard.hardware.rows', [
                'hw_filter' => 'all',
                'hw_progressive' => '1',
            ]))
            ->assertOk();

        $this->assertSame(10, substr_count((string) $response->json('rows_html'), 'data-hardware-select'));
        $this->assertSame(10, (int) $response->json('inspected_fulfilment_count'));
        $this->assertSame(25, (int) $response->json('unfiltered_total'));
        $this->assertSame(10, (int) $response->json('per_page'));
        $this->assertSame(2, (int) $response->json('last_page'));
        $this->assertStringContainsString('data-hardware-pagination-link', (string) $response->json('pagination_html'));
    }

    public function test_progressive_second_page_returns_next_batch(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $this->seedCatalog();

        for ($i = 1; $i <= 25; $i++) {
            $this->fulfilment(sprintf('RDE983%03d', $i), HardwareFulfilmentState::ReadyForFulfilment);
        }

        $response = $this->actingAs($admin)
            ->getJson(route('dashboard.hardware.rows', [
                'hw_filter' => 'all',
                'hw_progressive' => '1',
                'hw_page' => 2,
            ]))
            ->assertOk();

        $this->assertSame(15, substr_count((string) $response->json('rows_html'), 'data-hardware-select'));
        $this->assertSame(15, (int) $response->json('inspected_fulfilment_count'));
        $this->assertSame(40, (int) $response->json('per_page'));
        $this->assertSame(2, (int) $response->json('page'));
    }

    public function test_progressive_empty_results(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $this->seedCatalog();

        $response = $this->actingAs($admin)
            ->getJson(route('dashboard.hardware.rows', [
                'q' => 'DOES-NOT-EXIST-SEARCH-TERM',
                'hw_progressive' => '1',
            ]))
            ->assertOk();

        $this->assertSame(0, (int) $response->json('unfiltered_total'));
        $this->assertSame(0, (int) $response->json('inspected_fulfilment_count'));
        $this->assertStringNotContainsString('data-hardware-pagination-link', (string) $response->json('pagination_html'));
    }

    public function test_hardware_rows_endpoint_denied_without_permission(): void
    {
        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->syncPermissions(['incidents.view']);

        $this->actingAs($viewer)
            ->getJson(route('dashboard.hardware.rows', ['hw_progressive' => '1']))
            ->assertForbidden();
    }
}
