<?php

namespace Tests\Feature\Dashboard;

use App\Enums\HardwareFulfilmentState;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Dashboard\Concerns\CreatesHardwareDashboardFulfilments;
use Tests\TestCase;

class HardwareDashboardCountsDeferTest extends TestCase
{
    use CreatesHardwareDashboardFulfilments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
        $this->seedCatalog();
    }

    public function test_first_progressive_chunk_defers_cold_aggregate_sql(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->fulfilment('RDE-DEFER-1', HardwareFulfilmentState::ReadyForFulfilment);

        $response = $this->actingAs($admin)
            ->getJson(route('dashboard.hardware.rows', [
                'hw_filter' => 'all',
                'hw_progressive' => '1',
                'hw_chunk_limit' => 4,
                'hw_chunk_offset' => 0,
            ]))
            ->assertOk();

        $meta = $response->json('counts_meta');
        $this->assertIsArray($meta);
        $this->assertArrayHasKey('stale', $meta);

        $header = (string) $response->headers->get('Server-Timing');
        $this->assertNotSame('', $header);
        $this->assertStringContainsString('total', $header);
    }

    public function test_nav_counts_endpoint_returns_live_counts(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->fulfilment('RDE-COUNTS-1', HardwareFulfilmentState::ReadyForFulfilment);

        Cache::flush();

        $payload = $this->actingAs($admin)
            ->getJson(route('dashboard.hardware.counts', ['hw_filter' => 'all']))
            ->assertOk()
            ->json();

        $this->assertIsArray($payload['filter_counts'] ?? null);
        $this->assertFalse((bool) ($payload['stale'] ?? true));
    }
}
