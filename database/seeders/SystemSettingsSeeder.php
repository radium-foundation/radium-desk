<?php

namespace Database\Seeders;

use App\Models\SystemSetting;
use App\Services\SystemSettingsService;
use Illuminate\Database\Seeder;

class SystemSettingsSeeder extends Seeder
{
    public function run(): void
    {
        /** @var SystemSettingsService $systemSettingsService */
        $systemSettingsService = app(SystemSettingsService::class);

        foreach (config('system_settings.settings', []) as $key => $definition) {
            $default = $definition['default'] ?? null;
            $storedValue = $systemSettingsService->serializeValue($default, $definition['type'] ?? 'string');

            SystemSetting::query()->updateOrCreate(
                ['key' => $key],
                ['value' => $storedValue],
            );

            $systemSettingsService->forget($key);
        }
    }
}
