<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\ServiceCaseSlaStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\SettingProduct;
use App\Models\SettingSource;
use App\Models\User;
use App\Services\ServiceCaseAssignmentService;
use App\Services\SettingService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SystemSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
    }

    private function createSuperAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        return $user;
    }

    public function test_admin_cannot_access_settings(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->actingAs($admin)->get(route('settings.index'))->assertForbidden();
    }

    public function test_superadmin_can_view_settings(): void
    {
        $superadmin = $this->createSuperAdmin();

        $this->actingAs($superadmin)
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSee('System Settings')
            ->assertSee('General')
            ->assertSee('Assignment');
    }

    public function test_superadmin_can_update_general_settings(): void
    {
        $superadmin = $this->createSuperAdmin();

        $this->actingAs($superadmin)->put(route('settings.general.update'), [
            'company_name' => 'Radium Box',
            'company_email' => 'hello@radiumbox.com',
            'timezone' => 'Asia/Kolkata',
        ])
            ->assertRedirect(route('settings.index', ['tab' => 'general']))
            ->assertSessionHas('status', 'settings-general-updated');

        $this->assertSame('Radium Box', app(SettingService::class)->get('general.company_name'));
    }

    public function test_assignment_settings_affect_automatic_assignment(): void
    {
        $superadmin = $this->createSuperAdmin();
        $dayAdmin = User::factory()->create(['name' => 'Day Admin']);
        $dayAdmin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->actingAs($superadmin)->put(route('settings.assignment.update'), [
            'timezone' => 'Asia/Kolkata',
            'day_shift_start' => '09:00',
            'day_shift_end' => '18:30',
            'day_shift_admin_user_id' => $dayAdmin->id,
            'night_shift_admin_user_id' => $dayAdmin->id,
            'fallback_admin_1_user_id' => '',
            'fallback_admin_2_user_id' => '',
        ])->assertRedirect();

        Carbon::setTestNow(Carbon::parse('2026-06-24 12:00:00', 'Asia/Kolkata'));

        $assignee = app(ServiceCaseAssignmentService::class)->resolveAssignee();
        $this->assertTrue($assignee->is($dayAdmin));

        Carbon::setTestNow();
    }

    public function test_sla_settings_affect_service_case_sla_status(): void
    {
        $superadmin = $this->createSuperAdmin();

        $this->actingAs($superadmin)->put(route('settings.sla.update'), [
            'normal_warning_hours' => 2,
            'normal_overdue_hours' => 4,
            'priority_warning_hours' => 1,
            'priority_overdue_hours' => 2,
        ])->assertRedirect();

        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD-SLA-SETTINGS',
            'serial_number' => 'SN-SLA-SETTINGS',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-SLA-SETTINGS',
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'SLA settings test',
            'description' => 'SLA settings test.',
            'status' => 'open',
            'high_priority' => false,
            'created_by' => $admin->id,
        ]);
        $incident->forceFill(['created_at' => now()->subHours(3)])->saveQuietly();
        $incident->load('order');

        $this->assertSame(ServiceCaseSlaStatus::Warning, $incident->slaStatus());
    }

    public function test_superadmin_can_disable_product(): void
    {
        $superadmin = $this->createSuperAdmin();
        $product = SettingProduct::query()->where('name', 'MFS 110')->firstOrFail();

        $this->actingAs($superadmin)
            ->patch(route('settings.products.toggle', $product))
            ->assertRedirect();

        $this->assertFalse($product->fresh()->is_enabled);
        $this->assertNotContains('MFS 110', app(SettingService::class)->enabledProductNames());
    }

    public function test_superadmin_can_disable_source(): void
    {
        $superadmin = $this->createSuperAdmin();
        $source = SettingSource::query()->where('key', 'telegram')->firstOrFail();

        $this->actingAs($superadmin)
            ->patch(route('settings.sources.toggle', $source))
            ->assertRedirect();

        $this->assertFalse($source->fresh()->is_enabled);
        $this->assertNotContains('telegram', app(SettingService::class)->enabledSourceKeys());
    }

    public function test_setting_updates_refresh_cache(): void
    {
        $settingService = app(SettingService::class);
        $before = $settingService->get('general.company_name');

        $settingService->set('general.company_name', 'Cached Update Test');

        $this->assertSame('Cached Update Test', $settingService->get('general.company_name'));
        $this->assertNotSame($before, $settingService->get('general.company_name'));
    }
}
