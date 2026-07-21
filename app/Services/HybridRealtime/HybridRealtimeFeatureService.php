<?php

namespace App\Services\HybridRealtime;

use App\Services\SystemSettingsService;
use InvalidArgumentException;

class HybridRealtimeFeatureService
{
    public function __construct(
        private readonly SystemSettingsService $systemSettings,
    ) {}

    public function enabled(string $feature): bool
    {
        $definition = $this->definition($feature);

        if (! ($definition['wired'] ?? false)) {
            return false;
        }

        if ($this->envKillSwitchDisables($definition['env_kill_switch'] ?? null)) {
            return false;
        }

        $settingKey = $definition['setting_key'] ?? null;

        if (! is_string($settingKey) || $settingKey === '') {
            return false;
        }

        return $this->systemSettings->getBool($settingKey, false);
    }

    /**
     * @return array{setting_key: string, env_kill_switch: mixed, wired: bool}
     */
    public function definition(string $feature): array
    {
        $definition = config("hybrid_realtime.features.{$feature}");

        if (! is_array($definition)) {
            throw new InvalidArgumentException("Unknown hybrid realtime feature [{$feature}].");
        }

        return $definition;
    }

    private function envKillSwitchDisables(mixed $raw): bool
    {
        if ($raw === null) {
            return false;
        }

        return ! filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }
}
