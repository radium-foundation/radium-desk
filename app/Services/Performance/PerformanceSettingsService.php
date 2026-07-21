<?php

namespace App\Services\Performance;

use App\Services\SystemSettingsService;
use InvalidArgumentException;

class PerformanceSettingsService
{
    public const PROFILE_MANUAL = 'manual';

    public function __construct(
        private readonly SystemSettingsService $systemSettings,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function profiles(): array
    {
        return config('performance.profiles', []);
    }

    /**
     * @return list<string>
     */
    public function pollingKeys(): array
    {
        return config('performance.polling_keys', []);
    }

    public function currentProfile(): string
    {
        return (string) $this->systemSettings->get('performance.profile', 'balanced');
    }

    /**
     * @return array<string, int>
     */
    public function presetValues(string $profile): array
    {
        $profiles = $this->profiles();
        $definition = $profiles[$profile] ?? null;

        if (! is_array($definition)) {
            throw new InvalidArgumentException("Unknown performance profile [{$profile}].");
        }

        $values = $definition['values'] ?? [];

        return is_array($values) ? array_map('intval', $values) : [];
    }

    /**
     * @param  array<string, mixed>  $submitted
     * @return array<string, mixed>
     */
    public function resolveForSave(array $submitted): array
    {
        $profile = (string) ($submitted['performance.profile'] ?? $this->currentProfile());

        if (! $this->isValidProfile($profile)) {
            $profile = 'balanced';
        }

        $resolved = $submitted;
        $resolved['performance.profile'] = $profile;

        if ($profile === self::PROFILE_MANUAL) {
            foreach ($this->pollingKeys() as $key) {
                if (array_key_exists($key, $resolved)) {
                    $resolved[$key] = (int) $resolved[$key];
                }
            }

            return $resolved;
        }

        $preset = $this->presetValues($profile);
        $drift = false;

        foreach ($preset as $key => $expected) {
            $definition = config("system_settings.settings.{$key}", []);

            if (($definition['disabled'] ?? false) === true) {
                continue;
            }

            if (! array_key_exists($key, $submitted)) {
                continue;
            }

            if ((int) $submitted[$key] !== $expected) {
                $drift = true;

                break;
            }
        }

        if ($drift) {
            $resolved['performance.profile'] = self::PROFILE_MANUAL;

            foreach ($this->pollingKeys() as $key) {
                if (array_key_exists($key, $resolved)) {
                    $resolved[$key] = (int) $resolved[$key];
                }
            }

            return $resolved;
        }

        foreach ($preset as $key => $value) {
            $definition = config("system_settings.settings.{$key}", []);

            if (($definition['disabled'] ?? false) === true) {
                continue;
            }

            $resolved[$key] = $value;
        }

        return $resolved;
    }

    /**
     * @return array<string, array{
     *     key: string,
     *     label: string,
     *     description: string|null,
     *     type: string,
     *     value: mixed,
     *     disabled: bool,
     *     min: int|null,
     *     max: int|null,
     *     recommended: int|null,
     *     unit: string|null,
     *     updated_at: \Illuminate\Support\Carbon|null,
     *     updated_by_name: string|null
     * }>
     */
    public function pollingSettingsForAdmin(): array
    {
        $settings = [];

        foreach (config('system_settings.settings', []) as $key => $definition) {
            if (($definition['group'] ?? '') !== 'polling') {
                continue;
            }

            $row = \App\Models\SystemSetting::query()
                ->with('updatedBy')
                ->where('key', $key)
                ->first();

            $settings[$key] = [
                'key' => $key,
                'label' => $definition['label'],
                'description' => $definition['description'] ?? null,
                'type' => $definition['type'] ?? 'integer',
                'value' => $this->systemSettings->get($key, $definition['default'] ?? null),
                'disabled' => (bool) ($definition['disabled'] ?? false),
                'min' => isset($definition['min']) ? (int) $definition['min'] : null,
                'max' => isset($definition['max']) ? (int) $definition['max'] : null,
                'recommended' => isset($definition['recommended']) ? (int) $definition['recommended'] : null,
                'unit' => $definition['unit'] ?? null,
                'updated_at' => $row?->updated_at,
                'updated_by_name' => $row?->updatedBy?->name,
            ];
        }

        return $settings;
    }

    /**
     * @return list<array{
     *     key: string,
     *     label: string,
     *     description: string|null,
     *     type: string,
     *     value: mixed,
     *     disabled: bool,
     *     updated_at: \Illuminate\Support\Carbon|null,
     *     updated_by_name: string|null
     * }>
     */
    public function hybridRealtimeSettingsForAdmin(): array
    {
        $settings = [];

        foreach (config('system_settings.settings', []) as $key => $definition) {
            if (($definition['group'] ?? '') !== 'hybrid_realtime') {
                continue;
            }

            $row = \App\Models\SystemSetting::query()
                ->with('updatedBy')
                ->where('key', $key)
                ->first();

            $settings[] = [
                'key' => $key,
                'label' => $definition['label'],
                'description' => $definition['description'] ?? null,
                'type' => $definition['type'] ?? 'boolean',
                'value' => $this->systemSettings->get($key, $definition['default'] ?? null),
                'disabled' => (bool) ($definition['disabled'] ?? false),
                'updated_at' => $row?->updated_at,
                'updated_by_name' => $row?->updatedBy?->name,
            ];
        }

        return $settings;
    }

    /**
     * @return list<array{
     *     key: string,
     *     label: string,
     *     description: string|null,
     *     type: string,
     *     value: mixed,
     *     disabled: bool,
     *     min: int|null,
     *     max: int|null,
     *     recommended: int|null,
     *     unit: string|null,
     *     allowed: list<string>|null,
     *     updated_at: \Illuminate\Support\Carbon|null,
     *     updated_by_name: string|null
     * }>
     */
    public function notificationSettingsForAdmin(): array
    {
        $settings = [];

        foreach (config('system_settings.settings', []) as $key => $definition) {
            if (($definition['group'] ?? '') !== 'notifications') {
                continue;
            }

            $row = \App\Models\SystemSetting::query()
                ->with('updatedBy')
                ->where('key', $key)
                ->first();

            $settings[] = [
                'key' => $key,
                'label' => $definition['label'],
                'description' => $definition['description'] ?? null,
                'type' => $definition['type'] ?? 'string',
                'value' => $this->systemSettings->get($key, $definition['default'] ?? null),
                'disabled' => (bool) ($definition['disabled'] ?? false),
                'min' => isset($definition['min']) ? (int) $definition['min'] : null,
                'max' => isset($definition['max']) ? (int) $definition['max'] : null,
                'recommended' => isset($definition['recommended']) ? (int) $definition['recommended'] : null,
                'unit' => $definition['unit'] ?? null,
                'allowed' => $definition['allowed'] ?? null,
                'updated_at' => $row?->updated_at,
                'updated_by_name' => $row?->updatedBy?->name,
            ];
        }

        return $settings;
    }

    private function isValidProfile(string $profile): bool
    {
        return array_key_exists($profile, $this->profiles());
    }
}
