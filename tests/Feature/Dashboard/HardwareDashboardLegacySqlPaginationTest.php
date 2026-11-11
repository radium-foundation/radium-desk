<?php

namespace Tests\Feature\Dashboard;

use App\Enums\HardwareFulfilmentState;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Dashboard\Concerns\CreatesHardwareDashboardFulfilments;
use Tests\TestCase;

class HardwareDashboardLegacySqlPaginationTest extends TestCase
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

    public function test_exceptions_filter_sql_pagination_inspects_only_first_page(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $this->seedCatalog();

        for ($i = 1; $i <= 30; $i++) {
            $this->fulfilment(sprintf('RDE990%03d', $i), HardwareFulfilmentState::ReadyForFulfilment);
        }

        $this->fulfilment('RDE990999', HardwareFulfilmentState::ReadyForFulfilment, mapped: false);

        $response = $this->actingAs($admin)
            ->getJson(route('dashboard.hardware.rows', [
                'hw_filter' => 'exceptions',
                'hw_progressive' => '1',
            ]))
            ->assertOk();

        $this->assertSame(1, (int) $response->json('unfiltered_total'));
        $this->assertSame(1, (int) $response->json('inspected_fulfilment_count'));
        $this->assertStringContainsString('RDE990999', (string) $response->json('rows_html'));
    }

    public function test_scheduled_filter_sql_pagination_for_ingested_rows(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $this->seedCatalog();

        $this->fulfilment('RDE991001', HardwareFulfilmentState::Ingested);

        $response = $this->actingAs($admin)
            ->getJson(route('dashboard.hardware.rows', [
                'hw_filter' => 'scheduled',
            ]))
            ->assertOk();

        $this->assertGreaterThanOrEqual(1, (int) $response->json('unfiltered_total'));
        $this->assertSame(1, (int) $response->json('inspected_fulfilment_count'));
        $this->assertStringContainsString('RDE991001', (string) $response->json('rows_html'));
    }

    public function test_legacy_exceptions_queue_still_matches_sql_rows_api(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $this->seedCatalog();
        $this->fulfilment('RDE985001', HardwareFulfilmentState::ReadyForFulfilment, mapped: false);

        $response = $this->actingAs($admin)
            ->getJson(route('dashboard.hardware.rows', ['hw_queue' => 'exceptions']))
            ->assertOk();

        $this->assertStringContainsString('RDE985001', (string) $response->json('rows_html'));
        $this->assertSame(1, (int) $response->json('inspected_fulfilment_count'));
    }
}
