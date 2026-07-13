<?php

namespace Tests\Unit;

use App\Models\DeviceModel;
use App\Models\DeviceModelAlias;
use App\Services\DeviceModelAliasResolver;
use Database\Seeders\DeviceModelSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceModelAliasResolverTest extends TestCase
{
    use RefreshDatabase;

    private DeviceModelAliasResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(DeviceModelSeeder::class);

        $this->resolver = app(DeviceModelAliasResolver::class);
    }

    public function test_resolve_by_alias_returns_device_model(): void
    {
        $deviceModel = DeviceModel::query()->where('name', 'MFS110')->firstOrFail();

        DeviceModelAlias::query()->create([
            'device_model_id' => $deviceModel->id,
            'alias' => 'Morpho MFS110',
        ]);

        $resolved = $this->resolver->resolve('Morpho MFS110');

        $this->assertNotNull($resolved);
        $this->assertSame($deviceModel->id, $resolved->id);
    }

    public function test_resolve_by_alias_matches_normalized_variants(): void
    {
        $deviceModel = DeviceModel::query()->where('name', 'MFS110')->firstOrFail();

        $this->assertSame($deviceModel->id, $this->resolver->resolve('MFS 110')?->id);
        $this->assertSame($deviceModel->id, $this->resolver->resolve('mfs110')?->id);
        $this->assertSame($deviceModel->id, $this->resolver->resolve('MFS-110')?->id);
    }

    public function test_resolve_by_code_returns_active_device_model(): void
    {
        $deviceModel = DeviceModel::query()->where('name', 'L1')->firstOrFail();
        $deviceModel->update(['code' => 'l1code']);

        $resolved = $this->resolver->resolveByCode('L1-CODE');

        $this->assertNotNull($resolved);
        $this->assertSame($deviceModel->id, $resolved->id);
    }

    public function test_resolve_returns_null_for_unknown_label(): void
    {
        $this->assertNull($this->resolver->resolve('Totally Fake Model'));
    }

    public function test_duplicate_aliases_are_prevented_at_database_level(): void
    {
        $deviceModel = DeviceModel::query()->where('name', 'L1')->firstOrFail();

        DeviceModelAlias::query()->create([
            'device_model_id' => $deviceModel->id,
            'alias' => 'Access L1',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        DeviceModelAlias::query()->create([
            'device_model_id' => $deviceModel->id,
            'alias' => 'Access-L1',
        ]);
    }

    public function test_warm_lookup_resolves_without_additional_queries_pattern(): void
    {
        $deviceModel = DeviceModel::query()->where('name', 'MSO E3')->firstOrFail();

        DeviceModelAlias::query()->create([
            'device_model_id' => $deviceModel->id,
            'alias' => 'Morpho MSO E3',
        ]);

        $this->resolver->warmLookup();

        $this->assertSame($deviceModel->id, $this->resolver->resolve('Morpho MSO E3')?->id);
        $this->assertSame($deviceModel->id, $this->resolver->resolve('  mso   e3  ')?->id);
    }
}
