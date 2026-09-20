<?php

namespace Tests\Feature\Dashboard;

use App\Contracts\Shipping\ShiprocketGateway;
use App\Models\User;
use App\Services\DashboardPersonalizationService;
use App\Services\HardwareFulfilment\HardwareDashboardWorkspace;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Shipping\Support\FakeShiprocketGateway;
use Tests\TestCase;

class HardwareDashboardShiprocketBalanceTest extends TestCase
{
    use RefreshDatabase;

    private FakeShiprocketGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
        $this->withoutVite();
        Cache::flush();

        $this->gateway = new FakeShiprocketGateway;
        $this->app->instance(ShiprocketGateway::class, $this->gateway);

        config([
            'shipping.enabled' => true,
            'shipping.provider' => 'shiprocket',
            'shipping.http_enabled' => true,
            'shipping.api_email' => 'ship@example.test',
            'shipping.api_password' => 'secret',
            'shipping.wallet_balance_ttl_seconds' => 900,
            'shipping.wallet_balance_low_threshold' => '1000.00',
        ]);
    }

    public function test_hardware_dashboard_renders_shiprocket_balance_for_operators(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $this->gateway->walletBalanceAmount = '9084.26';

        $this->actingAs($admin)
            ->get(route('dashboard', ['queue' => 'hardware']))
            ->assertOk()
            ->assertSee('Shiprocket Balance')
            ->assertSee('₹9,084.26')
            ->assertSee('data-shiprocket-balance', false)
            ->assertSee('Checked', false);
    }

    public function test_hardware_dashboard_shows_balance_unavailable_on_provider_error(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $this->gateway->mode = 'rejected';

        $html = $this->actingAs($admin)
            ->get(route('dashboard', ['queue' => 'hardware']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Shiprocket Balance', $html);
        $this->assertStringContainsString('Balance unavailable', $html);
        $this->assertStringNotContainsString('₹0.00', $html);
    }

    public function test_hardware_workspace_presenter_omits_balance_without_operate_permission(): void
    {
        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->syncPermissions([
            DashboardPersonalizationService::PERMISSION_HARDWARE_VIEW,
            'incidents.view',
        ]);

        $request = Request::create('/dashboard', 'GET', ['queue' => 'hardware']);
        $request->setUserResolver(fn () => $viewer);

        $workspace = app(HardwareDashboardWorkspace::class)->present($request);

        $this->assertNull($workspace['shiprocketBalance']);
        $this->assertSame(0, $this->gateway->walletBalanceCalls);
    }

    public function test_zero_balance_renders_amount_not_unknown(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $this->gateway->walletBalanceAmount = '0.00';

        $this->actingAs($admin)
            ->get(route('dashboard', ['queue' => 'hardware']))
            ->assertOk()
            ->assertSee('₹0.00')
            ->assertSee('dashboard-shiprocket-balance--critical', false);
    }
}
