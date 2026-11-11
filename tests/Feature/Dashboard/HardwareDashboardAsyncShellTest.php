<?php

namespace Tests\Feature\Dashboard;

use App\Enums\HardwareFulfilmentState;
use App\Models\User;
use App\Services\DashboardPersonalizationService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Dashboard\Concerns\CreatesHardwareDashboardFulfilments;
use Tests\TestCase;

class HardwareDashboardAsyncShellTest extends TestCase
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

    public function test_hardware_dashboard_shell_defers_row_hydration(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $this->seedCatalog();

        $awaiting = $this->fulfilment('RDE980003', HardwareFulfilmentState::ReadyForFulfilment);

        $html = $this->actingAs($admin)
            ->followingRedirects()
            ->get(route('dashboard', ['workspace' => 'hardware']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-hardware-defer-rows="1"', $html);
        $this->assertStringContainsString('dashboard-hardware-skeleton-row', $html);
        $this->assertStringNotContainsString('RDE980003', $html);
        $this->assertStringContainsString('data-hardware-inspected-count="0"', $html);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $shellStarted = hrtime(true);
        $this->actingAs($admin)
            ->followingRedirects()
            ->get(route('dashboard', ['workspace' => 'hardware']))
            ->assertOk();
        $shellMs = (hrtime(true) - $shellStarted) / 1e6;
        $shellQueries = count(DB::getQueryLog());

        DB::flushQueryLog();
        $rowsStarted = hrtime(true);
        $response = $this->actingAs($admin)
            ->getJson(route('dashboard.hardware.rows', ['queue' => 'hardware']))
            ->assertOk();
        $rowsMs = (hrtime(true) - $rowsStarted) / 1e6;
        $rowsQueries = count(DB::getQueryLog());

        $this->assertStringContainsString('RDE980003', (string) $response->json('rows_html'));
        $this->assertGreaterThanOrEqual(1, (int) $response->json('filter_counts.awaiting_serial'));
        $this->assertLessThan(
            $rowsQueries,
            $shellQueries,
            "Expected shell query count ({$shellQueries}) to stay below rows API ({$rowsQueries})",
        );

        fwrite(STDERR, sprintf(
            "hardware shell ms=%.1f queries=%d | rows api ms=%.1f queries=%d\n",
            $shellMs,
            $shellQueries,
            $rowsMs,
            $rowsQueries,
        ));
    }

    public function test_hardware_rows_endpoint_requires_hardware_view_permission(): void
    {
        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->syncPermissions([
            'incidents.view',
        ]);
        $this->assertFalse($viewer->can(DashboardPersonalizationService::PERMISSION_HARDWARE_VIEW));

        $this->actingAs($viewer)
            ->getJson(route('dashboard.hardware.rows'))
            ->assertForbidden();
    }

    public function test_hw_filter_all_uses_sql_pagination_not_full_queue_scan(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $this->seedCatalog();

        for ($i = 1; $i <= 45; $i++) {
            $this->fulfilment(sprintf('RDE981%03d', $i), HardwareFulfilmentState::ReadyForFulfilment);
        }

        $response = $this->actingAs($admin)
            ->getJson(route('dashboard.hardware.rows', [
                'hw_filter' => 'all',
            ]))
            ->assertOk();

        $this->assertSame(40, substr_count((string) $response->json('rows_html'), 'data-hardware-select'));
        $this->assertSame(40, (int) $response->json('inspected_fulfilment_count'));
        $this->assertSame(45, (int) $response->json('unfiltered_total'));
        $this->assertStringContainsString('Page 1 of 2 (45)', (string) $response->json('pagination_html'));
    }
}
