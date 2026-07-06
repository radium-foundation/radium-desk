<?php

namespace Tests\Feature;

use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Models\Order;
use App\Models\User;
use App\Services\Operations\OperationsRadiumBoxHealthService;
use Carbon\CarbonInterface;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class OperationsRadiumBoxHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        config(['radiumbox.enabled' => true]);
    }

    public function test_operations_dashboard_includes_radiumbox_health_widget(): void
    {
        $admin = $this->createAdminUser();

        $this->actingAs($admin)
            ->get(route('admin.operations.index'))
            ->assertOk()
            ->assertSee('operations-health-trigger-radiumbox', false)
            ->assertSee('Expand to load RadiumBox details', false)
            ->assertSee('RadiumBox', false);

        $this->actingAs($admin)
            ->getJson(route('admin.operations.live', ['groups' => 'health_radiumbox']))
            ->assertOk()
            ->assertSee('RadiumBox Health', false)
            ->assertSee('Pending Syncs', false)
            ->assertSee('Failed Syncs', false)
            ->assertSee('Success Rate (24h)', false);
    }

    public function test_live_refresh_includes_radiumbox_health_html(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)
            ->getJson(route('admin.operations.live', ['groups' => 'health_radiumbox']));

        $response->assertOk();
        $response->assertJsonStructure(['html' => ['health_radiumbox']]);
        $this->assertStringContainsString(
            'RadiumBox Health',
            (string) $response->json('html.health_radiumbox'),
        );
    }

    public function test_cached_iso_date_is_hydrated_to_carbon_at_runtime(): void
    {
        $iso = '2026-07-05T14:30:00+05:30';

        Cache::put('operations:radiumbox-health', [
            'enabled' => true,
            'pending_syncs' => 0,
            'failed_syncs' => 0,
            'success_rate_24h' => 100.0,
            'average_sync_duration_ms' => null,
            'manual_retries_24h' => 0,
            'last_successful_sync_at' => $iso,
            'pending_orders' => [],
            'failed_orders' => [],
        ], now()->addMinute());

        $widget = app(OperationsRadiumBoxHealthService::class)->widget();

        $this->assertInstanceOf(CarbonInterface::class, $widget['last_successful_sync_at']);
        $this->assertSame($iso, $widget['last_successful_sync_at']->toIso8601String());
    }

    public function test_cached_null_last_successful_sync_at_remains_null_at_runtime(): void
    {
        Cache::put('operations:radiumbox-health', [
            'enabled' => true,
            'pending_syncs' => 0,
            'failed_syncs' => 0,
            'success_rate_24h' => 0.0,
            'average_sync_duration_ms' => null,
            'manual_retries_24h' => 0,
            'last_successful_sync_at' => null,
            'pending_orders' => [],
            'failed_orders' => [],
        ], now()->addMinute());

        $widget = app(OperationsRadiumBoxHealthService::class)->widget();

        $this->assertNull($widget['last_successful_sync_at']);
    }

    public function test_widget_cache_stores_last_successful_sync_at_as_iso8601_string(): void
    {
        Cache::forget('operations:radiumbox-health');

        app(OperationsRadiumBoxHealthService::class)->widget(useCache: false);

        $cached = Cache::get('operations:radiumbox-health');

        $this->assertIsArray($cached);
        $lastSuccessfulSyncAt = $cached['last_successful_sync_at'] ?? 'missing';
        $this->assertTrue($lastSuccessfulSyncAt === null || is_string($lastSuccessfulSyncAt));
    }

    public function test_health_service_aggregates_sync_status_counts(): void
    {
        $actor = User::factory()->create();

        Order::query()->create([
            'order_id' => 'RD-PENDING-1',
            'serial_number' => null,
            'status' => 'active',
            'created_by' => $actor->id,
            'radiumbox_sync_status' => RadiumBoxEnrichmentSyncStatus::Pending,
        ]);

        Order::query()->create([
            'order_id' => 'RD-FAILED-1',
            'serial_number' => null,
            'status' => 'active',
            'created_by' => $actor->id,
            'radiumbox_sync_status' => RadiumBoxEnrichmentSyncStatus::Failed,
        ]);

        $widget = app(OperationsRadiumBoxHealthService::class)->widget(useCache: false);

        $this->assertSame(1, $widget['pending_syncs']);
        $this->assertSame(1, $widget['failed_syncs']);
        $this->assertContains('RD-PENDING-1', array_column($widget['pending_orders'], 'order_id'));
        $this->assertContains('RD-FAILED-1', array_column($widget['failed_orders'], 'order_id'));
    }

    private function createAdminUser(): User
    {
        $user = User::factory()->create([
            'name' => 'Ops Admin',
            'email' => 'admin-radiumbox-health@test.com',
            'is_active' => true,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $user;
    }
}
