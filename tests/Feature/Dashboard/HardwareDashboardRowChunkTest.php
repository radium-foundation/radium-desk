<?php

namespace Tests\Feature\Dashboard;

use App\Enums\HardwareFulfilmentState;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Dashboard\Concerns\CreatesHardwareDashboardFulfilments;
use Tests\TestCase;

class HardwareDashboardRowChunkTest extends TestCase
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

    public function test_progressive_chunks_return_partial_rows_then_complete(): void
    {
        config([
            'dashboard.hardware_rows_first_chunk' => 4,
            'dashboard.hardware_rows_chunk_size' => 3,
        ]);

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        for ($i = 1; $i <= 12; $i++) {
            $this->fulfilment(sprintf('RDE-CHNK-%03d', $i), HardwareFulfilmentState::ReadyForFulfilment);
        }

        $first = $this->actingAs($admin)
            ->getJson(route('dashboard.hardware.rows', [
                'hw_filter' => 'all',
                'hw_progressive' => '1',
                'hw_chunk_limit' => 4,
                'hw_chunk_offset' => 0,
            ]))
            ->assertOk()
            ->json();

        $this->assertSame(4, substr_count((string) $first['rows_html'], 'data-hardware-select'));
        $this->assertFalse((bool) ($first['chunk']['complete'] ?? true));
        $this->assertSame(4, (int) ($first['chunk']['next_offset'] ?? 0));

        $second = $this->actingAs($admin)
            ->getJson(route('dashboard.hardware.rows', [
                'hw_filter' => 'all',
                'hw_progressive' => '1',
                'hw_chunk_limit' => 6,
                'hw_chunk_offset' => 4,
                'hw_skip_counts' => 1,
            ]))
            ->assertOk()
            ->json();

        $this->assertSame(6, substr_count((string) $second['rows_html'], 'data-hardware-select'));
        $this->assertSame([], $second['filter_counts']);
        $this->assertTrue((bool) ($second['chunk']['complete'] ?? false));
    }

    public function test_non_chunk_request_remains_backward_compatible(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->fulfilment('RDE-CHNK-ONE', HardwareFulfilmentState::ReadyForFulfilment);

        $response = $this->actingAs($admin)
            ->getJson(route('dashboard.hardware.rows', [
                'hw_filter' => 'all',
                'hw_progressive' => '1',
            ]))
            ->assertOk();

        $this->assertTrue((bool) ($response->json('chunk.complete') ?? false));
        $this->assertGreaterThan(0, substr_count((string) $response->json('rows_html'), 'data-hardware-select'));
    }
}
