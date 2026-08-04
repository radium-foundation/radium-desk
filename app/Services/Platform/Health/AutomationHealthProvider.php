<?php

namespace App\Services\Platform\Health;

use App\Contracts\Platform\PlatformHealthProvider;
use App\Data\Platform\PlatformHealthComponent;
use App\Enums\AutomationExecutionStatus;
use App\Enums\PlatformHealthStatus;
use App\Models\AutomationExecution;
use App\ReadModels\Automation\AutomationExecutionReadModel;
use App\Services\Operations\AutomationFailureClassifier;
use App\Services\SystemSettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class AutomationHealthProvider implements PlatformHealthProvider
{
    public function __construct(
        private readonly SystemSettingsService $systemSettings,
        private readonly AutomationExecutionReadModel $automationExecutions,
        private readonly AutomationFailureClassifier $failureClassifier,
    ) {}

    public function key(): string
    {
        return 'automation';
    }

    public function label(): string
    {
        return 'Automation';
    }

    public function sortOrder(): int
    {
        return 40;
    }

    public function probe(): PlatformHealthComponent
    {
        $checkedAt = now();

        if (! $this->systemSettings->getBool('automation.scheduler.enabled', false)) {
            return new PlatformHealthComponent(
                key: $this->key(),
                label: $this->label(),
                status: PlatformHealthStatus::Disabled,
                detail: 'Automation scheduler is turned off.',
                checkedAt: $checkedAt,
            );
        }

        if (! Schema::hasTable('automation_executions')) {
            return new PlatformHealthComponent(
                key: $this->key(),
                label: $this->label(),
                status: PlatformHealthStatus::Warning,
                detail: 'Enabled but execution history is unavailable.',
                checkedAt: $checkedAt,
            );
        }

        $overview = $this->automationExecutions->healthOverview();
        $lastRunRaw = $overview['last_execution_at'] ?? null;
        $lastRun = $lastRunRaw instanceof Carbon
            ? $lastRunRaw
            : (is_string($lastRunRaw) && $lastRunRaw !== '' ? Carbon::parse($lastRunRaw) : null);

        $openFailures24h = AutomationExecution::query()
            ->where('status', AutomationExecutionStatus::Failed)
            ->where('created_at', '>=', $checkedAt->copy()->subHours(24))
            ->get(['error_message'])
            ->filter(fn (AutomationExecution $execution): bool => $this->failureClassifier->isOpen($execution->error_message))
            ->count();

        $criticalFailures = (int) ($overview['critical_failures_today'] ?? 0);
        $historicalToday = (int) ($overview['historical_failures_today'] ?? 0);

        if ($lastRun === null) {
            return new PlatformHealthComponent(
                key: $this->key(),
                label: $this->label(),
                status: PlatformHealthStatus::Warning,
                detail: 'Enabled but no executions recorded yet.',
                checkedAt: $checkedAt,
            );
        }

        $hoursSinceRun = (int) $checkedAt->diffInHours($lastRun);

        if ($criticalFailures > 0 || $openFailures24h > 0) {
            $status = $criticalFailures > 0
                ? PlatformHealthStatus::Critical
                : PlatformHealthStatus::Warning;
            $detail = $criticalFailures > 0
                ? sprintf('%d critical automation failure(s) require attention.', $criticalFailures)
                : sprintf('%d open automation failure(s) in the last 24 hours.', $openFailures24h);
        } elseif ($hoursSinceRun >= 2) {
            $status = PlatformHealthStatus::Warning;
            $detail = 'Last automation execution was over 2 hours ago.';
        } else {
            $status = PlatformHealthStatus::Healthy;
            $detail = $historicalToday > 0
                ? sprintf('Healthy — %d historical failure(s) today are audit-only.', $historicalToday)
                : 'Automation scheduler is active.';
        }

        return new PlatformHealthComponent(
            key: $this->key(),
            label: $this->label(),
            status: $status,
            detail: $detail,
            checkedAt: $checkedAt,
            metrics: [
                'last_execution_at' => $lastRun->toIso8601String(),
                'open_failures_24h' => $openFailures24h,
                'critical_failures_today' => $criticalFailures,
                'historical_failures_today' => $historicalToday,
            ],
        );
    }
}
