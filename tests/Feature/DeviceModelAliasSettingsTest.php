<?php

namespace Tests\Feature;

use App\Models\DeviceModel;
use App\Models\DeviceModelAlias;
use App\Models\User;
use Database\Seeders\DeviceModelSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceModelAliasSettingsTest extends TestCase
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

    public function test_superadmin_can_view_aliases_section(): void
    {
        $superadmin = $this->createSuperAdmin();

        $this->actingAs($superadmin)
            ->get(route('settings.index', ['tab' => 'device-models']))
            ->assertOk()
            ->assertSee('Aliases')
            ->assertSee('MFS110');
    }

    public function test_superadmin_can_create_alias(): void
    {
        $superadmin = $this->createSuperAdmin();
        $deviceModel = DeviceModel::query()->where('name', 'MFS110')->firstOrFail();

        $this->actingAs($superadmin)
            ->post(route('settings.device-model-aliases.store'), [
                'device_model_id' => $deviceModel->id,
                'alias' => 'Mantra MFS110',
            ])
            ->assertRedirect(route('settings.index', ['tab' => 'device-models']))
            ->assertSessionHas('status', 'device-model-alias-created');

        $this->assertDatabaseHas('device_model_aliases', [
            'device_model_id' => $deviceModel->id,
            'alias' => 'Mantra MFS110',
            'normalized_alias' => 'mantramfs110',
        ]);
    }

    public function test_superadmin_can_update_alias(): void
    {
        $superadmin = $this->createSuperAdmin();
        $deviceModel = DeviceModel::query()->where('name', 'MFS110')->firstOrFail();
        $otherModel = DeviceModel::query()->where('name', 'L1')->firstOrFail();

        $alias = DeviceModelAlias::query()->create([
            'device_model_id' => $deviceModel->id,
            'alias' => 'Legacy MFS110',
        ]);

        $this->actingAs($superadmin)
            ->put(route('settings.device-model-aliases.update', $alias), [
                'device_model_id' => $otherModel->id,
                'alias' => 'Legacy L1 Label',
            ])
            ->assertRedirect(route('settings.index', ['tab' => 'device-models']))
            ->assertSessionHas('status', 'device-model-alias-updated');

        $this->assertDatabaseHas('device_model_aliases', [
            'id' => $alias->id,
            'device_model_id' => $otherModel->id,
            'alias' => 'Legacy L1 Label',
            'normalized_alias' => 'legacyl1label',
        ]);
    }

    public function test_superadmin_can_delete_alias(): void
    {
        $superadmin = $this->createSuperAdmin();
        $deviceModel = DeviceModel::query()->where('name', 'MFS110')->firstOrFail();

        $alias = DeviceModelAlias::query()->create([
            'device_model_id' => $deviceModel->id,
            'alias' => 'Temporary Alias',
        ]);

        $this->actingAs($superadmin)
            ->delete(route('settings.device-model-aliases.destroy', $alias))
            ->assertRedirect(route('settings.index', ['tab' => 'device-models']))
            ->assertSessionHas('status', 'device-model-alias-deleted');

        $this->assertDatabaseMissing('device_model_aliases', [
            'id' => $alias->id,
        ]);
    }

    public function test_duplicate_alias_is_rejected_on_create(): void
    {
        $superadmin = $this->createSuperAdmin();
        $deviceModel = DeviceModel::query()->where('name', 'L1')->firstOrFail();

        DeviceModelAlias::query()->create([
            'device_model_id' => $deviceModel->id,
            'alias' => 'Access L1',
        ]);

        $this->actingAs($superadmin)
            ->from(route('settings.index', ['tab' => 'device-models']))
            ->post(route('settings.device-model-aliases.store'), [
                'device_model_id' => $deviceModel->id,
                'alias' => 'Access-L1',
            ])
            ->assertRedirect(route('settings.index', ['tab' => 'device-models']))
            ->assertSessionHasErrors('alias');
    }
}
