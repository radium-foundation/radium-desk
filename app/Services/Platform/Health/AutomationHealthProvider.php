<?php

namespace App\Services\Platform\Health;

use App\Contracts\Platform\PlatformHealthProvider;
use App\Data\Platform\PlatformHealthComponent;
use App\Enums\AutomationExecutionStatus;
use App\Enums\PlatformHealthStatus;
use App\Models\AutomationExecution;
use App\ReadModels\Automation\AutomationExecutionReadModel;
use App\Services\SystemSettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class AutomationHealthProvider implements PlatformHealthProvider
{
    public function __construct(
        private readonly SystemSettingsService $systemSettings,
        private readonly AutomationExecutionReadModel $automationExecutions,
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

        // Reuse H4 AutomationExecutionReadModel (60s aggregation cache) for last-run timestamp.
        $overview = $this->automationExecutions->healthOverview();
        $lastRunRaw = $overview['last_execution_at'] ?? null;
        $lastRun = $lastRunRaw instanceof Carbon
            ? $lastRunRaw
            : (is_string($lastRunRaw) && $lastRunRaw !== '' ? Carbon::parse($lastRunRaw) : null);

        // Keep the existing 24h failure window semantics (not failures_today).
        $recentFailure = AutomationExecution::query()
            ->where('status', AutomationExecutionStatus::Failed)
            ->where('created_at', '>=', $checkedAt->copy()->subHours(24))
            ->exists();

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

        if ($recentFailure) {
            $status = PlatformHealthStatus::Warning;
            $detail = 'Failures recorded in the last 24 hours.';
        } elseif ($hoursSinceRun >= 2) {
            $status = PlatformHealthStatus::Warning;
            $detail = 'Last automation execution was over 2 hours ago.';
        } else {
            $status = PlatformHealthStatus::Healthy;
            $detail = 'Automation scheduler is active.';
        }

        return new PlatformHealthComponent(
            key: $this->key(),
            label: $this->label(),
            status: $status,
            detail: $detail,
            checkedAt: $checkedAt,
            metrics: [
                'last_execution_at' => $lastRun->toIso8601String(),
                'recent_failure' => $recentFailure,
            ],
        );
    }
}
