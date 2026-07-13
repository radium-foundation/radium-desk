<?php

namespace Database\Seeders;

use App\Models\DeviceModel;
use App\Models\DeviceModelAlias;
use App\Services\DeviceModelAliasNormalizer;
use Illuminate\Database\Seeder;

class DeviceModelSeeder extends Seeder
{
    public function run(): void
    {
        $normalizer = app(DeviceModelAliasNormalizer::class);

        foreach (config('device_models', []) as $index => $name) {
            $deviceModel = DeviceModel::query()->updateOrCreate(
                ['name' => $name],
                [
                    'display_order' => $index + 1,
                    'is_active' => true,
                ],
            );

            $this->seedAliasesForModel($deviceModel, $normalizer);
        }
    }

    private function seedAliasesForModel(DeviceModel $deviceModel, DeviceModelAliasNormalizer $normalizer): void
    {
        foreach (array_filter([$deviceModel->name, $deviceModel->code]) as $alias) {
            $normalizedAlias = $normalizer->normalize((string) $alias);

            if ($normalizedAlias === '') {
                continue;
            }

            DeviceModelAlias::query()->updateOrCreate(
                ['normalized_alias' => $normalizedAlias],
                [
                    'device_model_id' => $deviceModel->id,
                    'alias' => (string) $alias,
                ],
            );
        }
    }
}
