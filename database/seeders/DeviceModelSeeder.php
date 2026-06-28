<?php

namespace Database\Seeders;

use App\Models\DeviceModel;
use Illuminate\Database\Seeder;

class DeviceModelSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('device_models', []) as $index => $name) {
            DeviceModel::query()->updateOrCreate(
                ['name' => $name],
                [
                    'display_order' => $index + 1,
                    'is_active' => true,
                ],
            );
        }
    }
}
