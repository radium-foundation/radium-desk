<?php

namespace Tests\Feature\HardwareFulfilment;

use App\Enums\IncidentSource;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HardwareWorkspaceRefreshTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_hardware_workspace_refresh_returns_authoritative_html_and_counts(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $creator = User::factory()->create(['is_active' => true]);
        $order = Order::query()->create([
            'order_id' => 'RDE902040',
            'customer_name' => 'RAMESH KUMAR',
            'cashfree_payment_id' => 'cf_RDE902040',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $creator->id,
            'created_at' => '2026-09-10 19:16:00',
            'updated_at' => '2026-09-10 19:16:00',
        ]);

        Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Hardware case RDE902040',
            'description' => 'Hardware dashboard case.',
            'status' => 'open',
            'created_by' => $creator->id,
        ]);

        $response = $this->actingAs($admin)
            ->getJson(route('dashboard.hardware-workspace', ['workspace' => 'hardware']));

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonStructure([
                'workspace_html',
                'counts' => ['ready', 'exceptions', 'pickup', 'completed'],
                'hardware_chip_count',
            ]);

        $html = (string) $response->json('workspace_html');
        $this->assertStringContainsString('data-hardware-workspace', $html);
        $this->assertStringContainsString('RDE902040', $html);
        $this->assertStringContainsString('dashboard-hardware-datetime__label', $html);
        $this->assertStringContainsString('Order', $html);
        $this->assertStringContainsString('Last action', $html);
        $this->assertStringContainsString('dashboard-hardware-datetime__line', $html);
        $this->assertStringContainsString('datetime=', $html);
    }

    public function test_hardware_workspace_refresh_requires_hardware_access(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)
            ->getJson(route('dashboard.hardware-workspace', ['workspace' => 'hardware']))
            ->assertForbidden();
    }
}
