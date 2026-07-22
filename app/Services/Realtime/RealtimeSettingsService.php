<?php

namespace App\Services\Realtime;

use App\Models\SystemSetting;
use App\Services\SystemSettingsService;

class RealtimeSettingsService
{
    public function __construct(
        private readonly SystemSettingsService $systemSettings,
        private readonly RealtimeRuntimeConfig $runtimeConfig,
        private readonly RealtimeConnectionStatusService $connectionStatus,
    ) {}

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
     *     unit: string|null,
     *     allowed: list<string>|null,
     *     updated_at: \Illuminate\Support\Carbon|null,
     *     updated_by_name: string|null
     * }>
     */
    public function settingsForAdmin(): array
    {
        $settings = [];

        foreach (config('system_settings.settings', []) as $key => $definition) {
            if (($definition['group'] ?? '') !== 'realtime') {
                continue;
            }

            $row = SystemSetting::query()
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
                'unit' => $definition['unit'] ?? null,
                'allowed' => $definition['allowed'] ?? null,
                'updated_at' => $row?->updated_at,
                'updated_by_name' => $row?->updatedBy?->name,
            ];
        }

        return $settings;
    }

    /**
     * @return array<string, mixed>
     */
    public function healthSnapshot(): array
    {
        $connection = $this->connectionStatus->snapshot();

        return [
            'configured_provider' => (string) $this->systemSettings->get('realtime.provider', RealtimeRuntimeConfig::PROVIDER_AUTO),
            'effective_provider' => $this->runtimeConfig->provider(),
            'connection_status' => $connection['status'] ?? 'unknown',
            'polling_active' => (bool) ($connection['polling_active'] ?? false),
            'last_connected_at' => $connection['last_connected_at'] ?? null,
            'last_disconnected_at' => $connection['last_disconnected_at'] ?? null,
            'last_disconnect_reason' => $connection['last_disconnect_reason'] ?? $connection['last_error'] ?? null,
            'last_error' => $connection['last_error'] ?? null,
            'reported_at' => $connection['reported_at'] ?? null,
            'reported_by_user_id' => $connection['reported_by_user_id'] ?? null,
        ];
    }
}
