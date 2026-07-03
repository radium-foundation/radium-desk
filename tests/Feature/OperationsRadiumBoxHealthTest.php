<?php

namespace Tests\Feature;

use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Models\Order;
use App\Models\User;
use App\Services\Operations\OperationsRadiumBoxHealthService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->assertSee('RadiumBox Health', false)
            ->assertSee('Pending Syncs', false)
            ->assertSee('Failed Syncs', false)
            ->assertSee('Success Rate (24h)', false);
    }

    public function test_live_refresh_includes_radiumbox_health_html(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)
            ->getJson(route('admin.operations.live'));

        $response->assertOk();
        $response->assertJsonStructure(['html' => ['radiumbox_health']]);
        $this->assertStringContainsString(
            'RadiumBox Health',
            (string) $response->json('html.radiumbox_health'),
        );
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
