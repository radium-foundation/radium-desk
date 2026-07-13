<?php

namespace Tests\Feature;

use App\Models\DeviceModel;
use App\Models\DeviceModelAlias;
use App\Models\Order;
use App\Models\User;
use App\Services\DeviceModelAliasResolver;
use Database\Seeders\DeviceModelSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceModelImportResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(DeviceModelSeeder::class);
    }

    public function test_import_label_resolves_to_canonical_device_model_id(): void
    {
        $deviceModel = DeviceModel::query()->where('name', 'MFS110')->firstOrFail();

        DeviceModelAlias::query()->create([
            'device_model_id' => $deviceModel->id,
            'alias' => 'RadiumBox MFS-110',
        ]);

        $resolver = app(DeviceModelAliasResolver::class);
        $resolved = $resolver->resolve('RadiumBox MFS-110');

        $this->assertNotNull($resolved);
        $this->assertSame($deviceModel->id, $resolved->id);
    }

    public function test_backfill_uses_alias_resolver_for_vendor_prefixed_import_labels(): void
    {
        config([
            'cashfree.system_user_email' => 'superadmin@radium.local',
        ]);

        $systemUser = User::factory()->create([
            'email' => 'superadmin@radium.local',
            'first_name' => 'Ira',
            'last_name' => 'Automation',
        ]);
        $systemUser->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $deviceModel = DeviceModel::query()->where('name', 'Morpho 1300')->firstOrFail();

        DeviceModelAlias::query()->create([
            'device_model_id' => $deviceModel->id,
            'alias' => 'Morpho MSO 1300',
        ]);

        $order = Order::query()->create([
            'order_id' => 'RD-IMPORT-ALIAS',
            'serial_number' => null,
            'product_name' => 'Morpho MSO 1300',
            'device_model' => 'Morpho MSO 1300',
            'device_model_id' => null,
            'status' => 'active',
            'created_by' => $systemUser->id,
        ]);

        $this->artisan('device-models:backfill', ['--force' => true])
            ->expectsConfirmation('You are about to assign device models to 1 order(s). Continue?', 'yes')
            ->assertSuccessful()
            ->expectsOutputToContain('Assigned: 1');

        $this->assertSame($deviceModel->id, $order->fresh()->device_model_id);
    }
}
