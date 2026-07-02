<?php

namespace App\Services\Operations;

use App\Enums\AutomationExecutionStatus;
use App\Enums\OperationsHealthStatus;
use App\Models\AutomationExecution;
use App\Models\InteraktMessage;
use App\Services\SystemSettingsService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;

class OperationsSystemHealthService
{
    public function __construct(
        private readonly SystemSettingsService $systemSettings,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function components(?OperationsDashboardSnapshot $snapshot = null): array
    {
        return [
            $this->automationRuntime($snapshot),
            $this->scheduler($snapshot),
            $this->queueWorker($snapshot),
            $this->notificationDispatcher($snapshot),
            $this->email($snapshot),
            $this->whatsapp($snapshot),
            $this->telegram($snapshot),
            $this->desktopNotifications($snapshot),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function automationRuntime(?OperationsDashboardSnapshot $snapshot): array
    {
        if (! $this->systemSettings->getBool('automation.scheduler.enabled', false)) {
            return $this->component('automation_runtime', 'Automation Runtime', OperationsHealthStatus::Disabled, 'Scheduler is disabled.');
        }

        if (! Schema::hasTable('automation_executions')) {
            return $this->component('automation_runtime', 'Automation Runtime', OperationsHealthStatus::NotConfigured, 'Execution table unavailable.');
        }

        $recentFailure = $snapshot !== null
            ? $snapshot->hasRecentAutomationFailure()
            : AutomationExecution::query()
                ->where('status', AutomationExecutionStatus::Failed)
                ->where('created_at', '>=', now()->subHours(24))
                ->exists();
        $recentSuccess = $snapshot !== null
            ? $snapshot->hasRecentAutomationSuccess()
            : AutomationExecution::query()
                ->where('status', AutomationExecutionStatus::Success)
                ->where('created_at', '>=', now()->subDays(7))
                ->exists();

        if ($recentFailure) {
            return $this->component('automation_runtime', 'Automation Runtime', OperationsHealthStatus::Warning, 'Failures recorded in the last 24 hours.');
        }

        if ($recentSuccess) {
            return $this->component('automation_runtime', 'Automation Runtime', OperationsHealthStatus::Healthy, 'Recent executions completed successfully.');
        }

        return $this->component('automation_runtime', 'Automation Runtime', OperationsHealthStatus::Healthy, 'Enabled with no recent failures.');
    }

    /**
     * @return array<string, mixed>
     */
    private function scheduler(?OperationsDashboardSnapshot $snapshot): array
    {
        if (! $this->systemSettings->getBool('automation.scheduler.enabled', false)) {
            return $this->component('scheduler', 'Scheduler', OperationsHealthStatus::Disabled, 'Automation scheduler is turned off.');
        }

        if (! Schema::hasTable('automation_executions')) {
            return $this->component('scheduler', 'Scheduler', OperationsHealthStatus::Warning, 'Enabled but execution history is unavailable.');
        }

        $lastRun = $snapshot !== null
            ? $snapshot->lastAutomationExecutionAt()
            : AutomationExecution::query()->latest('created_at')->value('created_at');

        if ($lastRun === null) {
            return $this->component('scheduler', 'Scheduler', OperationsHealthStatus::Warning, 'Enabled but no executions recorded yet.');
        }

        $hoursSinceRun = now()->diffInHours($lastRun);

        if ($hoursSinceRun >= 2) {
            return $this->component('scheduler', 'Scheduler', OperationsHealthStatus::Warning, 'Last execution was over 2 hours ago.');
        }

        return $this->component('scheduler', 'Scheduler', OperationsHealthStatus::Healthy, 'Scheduler is active.');
    }

    /**
     * @return array<string, mixed>
     */
    private function queueWorker(?OperationsDashboardSnapshot $snapshot): array
    {
        if (! (bool) config('infrastructure.queue_cron_worker_enabled')) {
            return $this->component('queue_worker', 'Queue Worker', OperationsHealthStatus::Disabled, 'Cron queue worker is disabled.');
        }

        if ($snapshot !== null) {
            $queueSnapshot = $snapshot->queueSnapshot();
        } else {
            $queueMetricsService = app(\App\Infrastructure\Queue\QueueMetricsService::class);
            $queueSnapshot = $queueMetricsService->latest() ?? $queueMetricsService->capture();
        }

        if ($queueSnapshot->failedJobs > 0) {
            return $this->component('queue_worker', 'Queue Worker', OperationsHealthStatus::Failed, "{$queueSnapshot->failedJobs} failed job(s) in dead-letter queue.");
        }

        if ($queueSnapshot->pendingJobs > 50) {
            return $this->component('queue_worker', 'Queue Worker', OperationsHealthStatus::Warning, "{$queueSnapshot->pendingJobs} pending job(s) waiting.");
        }

        if ($queueSnapshot->oldestPendingJobAt !== null && $queueSnapshot->oldestPendingJobAt->lt(now()->subMinutes(30))) {
            return $this->component('queue_worker', 'Queue Worker', OperationsHealthStatus::Warning, 'Oldest pending job is over 30 minutes old.');
        }

        return $this->component('queue_worker', 'Queue Worker', OperationsHealthStatus::Healthy, 'Queue worker is processing normally.');
    }

    /**
     * @return array<string, mixed>
     */
    private function notificationDispatcher(?OperationsDashboardSnapshot $snapshot): array
    {
        if (! Schema::hasTable('audit_logs')) {
            return $this->component('notification_dispatcher', 'Notification Dispatcher', OperationsHealthStatus::NotConfigured, 'Audit log table unavailable.');
        }

        $auditAggregator = $snapshot?->auditAggregator()
            ?? new OperationsAuditAggregator(collect());

        $todayFailures = $auditAggregator->dispatchesWithChannelFailuresCount();
        $todayDispatches = $auditAggregator->todayDispatchCount();

        if ($todayDispatches === 0) {
            return $this->component('notification_dispatcher', 'Notification Dispatcher', OperationsHealthStatus::Healthy, 'No dispatches recorded today.');
        }

        if ($todayFailures > 0) {
            return $this->component('notification_dispatcher', 'Notification Dispatcher', OperationsHealthStatus::Warning, "{$todayFailures} dispatch(es) with channel failures today.");
        }

        return $this->component('notification_dispatcher', 'Notification Dispatcher', OperationsHealthStatus::Healthy, "{$todayDispatches} dispatch(es) today without failures.");
    }

    /**
     * @return array<string, mixed>
     */
    private function email(?OperationsDashboardSnapshot $snapshot): array
    {
        return $this->channelHealth(
            key: 'email',
            label: 'Email (ZeptoMail)',
            enabledSetting: 'notifications.email.enabled',
            apiSetting: 'email.api_enabled',
            channel: 'email',
            configuredCheck: fn (): bool => (bool) config('mail.enabled') && config('mail.default') !== 'log',
            snapshot: $snapshot,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function whatsapp(?OperationsDashboardSnapshot $snapshot): array
    {
        return $this->channelHealth(
            key: 'whatsapp',
            label: 'WhatsApp (Interakt)',
            enabledSetting: 'notifications.whatsapp.enabled',
            apiSetting: 'whatsapp.api_enabled',
            channel: 'whatsapp',
            configuredCheck: fn (): bool => filled(Config::get('interakt.api_key')),
            extraConfiguredCheck: fn (): bool => Schema::hasTable('interakt_messages')
                && ($snapshot !== null
                    ? $snapshot->interaktInputs()['has_recent_activity']
                    : InteraktMessage::query()->where('created_at', '>=', now()->subDays(30))->exists()),
            snapshot: $snapshot,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function telegram(?OperationsDashboardSnapshot $snapshot): array
    {
        return $this->channelHealth(
            key: 'telegram',
            label: 'Telegram',
            enabledSetting: 'notifications.telegram.enabled',
            apiSetting: 'telegram.api_enabled',
            channel: 'telegram',
            configuredCheck: fn (): bool => $this->systemSettings->getBool('telegram.api_enabled', false),
            snapshot: $snapshot,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function desktopNotifications(?OperationsDashboardSnapshot $snapshot): array
    {
        return $this->channelHealth(
            key: 'desktop',
            label: 'Desktop Notifications',
            enabledSetting: 'notifications.desktop.enabled',
            apiSetting: null,
            channel: 'desktop',
            configuredCheck: fn (): bool => true,
            snapshot: $snapshot,
        );
    }

    /**
     * @param  callable(): bool  $configuredCheck
     * @param  (callable(): bool)|null  $extraConfiguredCheck
     * @return array<string, mixed>
     */
    private function channelHealth(
        string $key,
        string $label,
        string $enabledSetting,
        ?string $apiSetting,
        string $channel,
        callable $configuredCheck,
        ?OperationsDashboardSnapshot $snapshot = null,
        ?callable $extraConfiguredCheck = null,
    ): array {
        if (! $this->systemSettings->getBool($enabledSetting, false)) {
            return $this->component($key, $label, OperationsHealthStatus::Disabled, 'Channel is disabled in system settings.');
        }

        if ($apiSetting !== null && ! $this->systemSettings->getBool($apiSetting, false)) {
            return $this->component($key, $label, OperationsHealthStatus::Disabled, 'API integration is disabled.');
        }

        if (! $configuredCheck()) {
            return $this->component($key, $label, OperationsHealthStatus::NotConfigured, 'Integration credentials or transport are not configured.');
        }

        if ($extraConfiguredCheck !== null && ! $extraConfiguredCheck()) {
            return $this->component($key, $label, OperationsHealthStatus::Warning, 'Configured but no recent activity detected.');
        }

        $todayFailure = $snapshot !== null
            ? $snapshot->auditAggregator()->channelFailuresToday($channel)
            : 0;

        if ($todayFailure > 0) {
            return $this->component($key, $label, OperationsHealthStatus::Warning, "{$todayFailure} channel failure(s) today.");
        }

        return $this->component($key, $label, OperationsHealthStatus::Healthy, 'Channel is enabled and healthy.');
    }

    /**
     * @return array<string, mixed>
     */
    private function component(string $key, string $label, OperationsHealthStatus $status, string $detail): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'status' => $status->value,
            'status_label' => $status->label(),
            'badge_class' => $status->badgeClass(),
            'detail' => $detail,
        ];
    }
}
