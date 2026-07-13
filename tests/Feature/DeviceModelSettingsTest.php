<?php

namespace Tests\Feature;

use App\Models\DeviceModel;
use App\Models\User;
use Database\Seeders\DeviceModelSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceModelSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(DeviceModelSeeder::class);
    }

    private function createSuperAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        return $user;
    }

    public function test_superadmin_can_view_device_models_settings_tab(): void
    {
        $superadmin = $this->createSuperAdmin();

        $this->actingAs($superadmin)
            ->get(route('settings.index', ['tab' => 'device-models']))
            ->assertOk()
            ->assertSee('Models')
            ->assertSee('MFS110');
    }

    public function test_superadmin_can_create_device_model(): void
    {
        $superadmin = $this->createSuperAdmin();

        $this->actingAs($superadmin)
            ->post(route('settings.device-models.store'), [
                'name' => 'Test Model X',
                'code' => 'TMX',
                'brand' => 'TestBrand',
                'driver_download_url' => 'https://radiumbox.com/drivers/test-model-x',
                'buy_device_url' => 'https://radiumbox.com/shop/test-model-x',
                'buy_rd_service_url' => 'https://radiumbox.com/rd-service/test-model-x',
                'display_order' => 99,
            ])
            ->assertRedirect(route('settings.index', ['tab' => 'device-models']))
            ->assertSessionHas('status', 'device-model-created');

        $this->assertDatabaseHas('device_models', [
            'name' => 'Test Model X',
            'code' => 'TMX',
            'brand' => 'TestBrand',
            'driver_download_url' => 'https://radiumbox.com/drivers/test-model-x',
            'buy_device_url' => 'https://radiumbox.com/shop/test-model-x',
            'buy_rd_service_url' => 'https://radiumbox.com/rd-service/test-model-x',
            'is_active' => true,
        ]);
    }

    public function test_superadmin_can_update_device_model_catalog_urls(): void
    {
        $superadmin = $this->createSuperAdmin();
        $deviceModel = DeviceModel::query()->where('name', 'Morpho 1300')->firstOrFail();

        $this->actingAs($superadmin)
            ->put(route('settings.device-models.update', $deviceModel), [
                'name' => $deviceModel->name,
                'code' => $deviceModel->code,
                'brand' => $deviceModel->brand,
                'driver_download_url' => 'https://radiumbox.com/drivers/morpho-1300',
                'buy_device_url' => 'https://radiumbox.com/shop/morpho-1300',
                'buy_rd_service_url' => 'https://radiumbox.com/rd-service/morpho-1300',
                'display_order' => $deviceModel->display_order,
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'device-model-updated');

        $fresh = $deviceModel->fresh();

        $this->assertSame('https://radiumbox.com/drivers/morpho-1300', $fresh->driver_download_url);
        $this->assertSame('https://radiumbox.com/shop/morpho-1300', $fresh->buy_device_url);
        $this->assertSame('https://radiumbox.com/rd-service/morpho-1300', $fresh->buy_rd_service_url);
    }

    public function test_store_rejects_invalid_catalog_urls(): void
    {
        $superadmin = $this->createSuperAdmin();

        $this->actingAs($superadmin)
            ->post(route('settings.device-models.store'), [
                'name' => 'Invalid URL Model',
                'display_order' => 50,
                'driver_download_url' => 'not-a-url',
                'buy_device_url' => 'also-invalid',
                'buy_rd_service_url' => str_repeat('a', 501),
            ])
            ->assertSessionHasErrors([
                'driver_download_url',
                'buy_device_url',
                'buy_rd_service_url',
            ]);
    }

    public function test_superadmin_can_update_device_model_driver_download_url(): void
    {
        $superadmin = $this->createSuperAdmin();
        $deviceModel = DeviceModel::query()->where('name', 'MFS110')->firstOrFail();

        $this->actingAs($superadmin)
            ->put(route('settings.device-models.update', $deviceModel), [
                'name' => $deviceModel->name,
                'code' => $deviceModel->code,
                'brand' => $deviceModel->brand,
                'driver_download_url' => 'https://radiumbox.com/drivers/mfs110',
                'display_order' => $deviceModel->display_order,
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'device-model-updated');

        $this->assertSame(
            'https://radiumbox.com/drivers/mfs110',
            $deviceModel->fresh()->driver_download_url,
        );
    }

    public function test_superadmin_can_search_device_models(): void
    {
        $superadmin = $this->createSuperAdmin();

        $this->actingAs($superadmin)
            ->get(route('settings.index', ['tab' => 'device-models', 'search' => 'Morpho']))
            ->assertOk()
            ->assertSee('Morpho 1300')
            ->assertDontSee('MFS110');
    }

    public function test_superadmin_can_deactivate_device_model(): void
    {
        $superadmin = $this->createSuperAdmin();
        $deviceModel = DeviceModel::query()->where('name', 'L0')->firstOrFail();

        $this->actingAs($superadmin)
            ->patch(route('settings.device-models.toggle', $deviceModel))
            ->assertRedirect()
            ->assertSessionHas('status', 'device-model-deactivated');

        $this->assertFalse($deviceModel->fresh()->is_active);
    }

    public function test_admin_cannot_access_device_model_settings(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->actingAs($admin)
            ->get(route('settings.index', ['tab' => 'device-models']))
            ->assertForbidden();
    }
}
