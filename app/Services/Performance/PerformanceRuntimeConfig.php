<?php

namespace App\Services\Performance;

use App\Services\SystemSettingsService;

class PerformanceRuntimeConfig
{
    public function __construct(
        private readonly SystemSettingsService $systemSettings,
    ) {}

    public function dashboardPollIntervalMs(): int
    {
        return $this->pollingInt('performance.polling.dashboard_live_ms');
    }

    public function notificationPollIntervalMs(): int
    {
        return $this->pollingInt('performance.polling.notification_ms');
    }

    public function operationsPollIntervalMs(): int
    {
        return $this->pollingInt('performance.polling.operations_ms');
    }

    public function operationsFullRefreshIntervalMs(): int
    {
        return $this->pollingInt('performance.polling.operations_full_refresh_ms');
    }

    public function customer360TimelinePollIntervalMs(): int
    {
        return $this->pollingInt('performance.polling.customer360_timeline_ms');
    }

    public function customer360DeviceSyncPollIntervalMs(): int
    {
        return $this->pollingInt('performance.polling.customer360_device_sync_ms');
    }

    public function presenceHeartbeatIntervalSeconds(): int
    {
        return $this->pollingInt('performance.polling.presence_heartbeat_seconds');
    }

    public function agentReminderIntervalSeconds(): int
    {
        return $this->pollingInt('performance.polling.agent_reminder_seconds');
    }

    public function executiveDashboardPollIntervalSeconds(): int
    {
        return $this->pollingInt('performance.polling.executive_dashboard_seconds');
    }

    /**
     * @return array<string, int>
     */
    public function forBlade(): array
    {
        return [
            'dashboardPollIntervalMs' => $this->dashboardPollIntervalMs(),
            'notificationPollIntervalMs' => $this->notificationPollIntervalMs(),
            'operationsPollIntervalMs' => $this->operationsPollIntervalMs(),
            'operationsFullRefreshIntervalMs' => $this->operationsFullRefreshIntervalMs(),
            'customer360TimelinePollIntervalMs' => $this->customer360TimelinePollIntervalMs(),
            'customer360DeviceSyncPollIntervalMs' => $this->customer360DeviceSyncPollIntervalMs(),
            'presenceHeartbeatIntervalSeconds' => $this->presenceHeartbeatIntervalSeconds(),
            'agentReminderIntervalSeconds' => $this->agentReminderIntervalSeconds(),
            'executiveDashboardPollIntervalSeconds' => $this->executiveDashboardPollIntervalSeconds(),
        ];
    }

    private function pollingInt(string $key): int
    {
        $fallbacks = config('performance.fallbacks', []);
        $default = (int) ($fallbacks[$key] ?? 0);

        return (int) $this->systemSettings->get($key, $default);
    }
}
