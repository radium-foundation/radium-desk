<?php

namespace App\Services\Platform\Health;

use App\Contracts\Platform\PlatformHealthProvider;
use App\Data\Platform\PlatformHealthComponent;
use App\Enums\AutomationExecutionStatus;
use App\Enums\PlatformHealthStatus;
use App\Models\AutomationExecution;
use App\Services\SystemSettingsService;
use Illuminate\Support\Facades\Schema;

class AutomationHealthProvider implements PlatformHealthProvider
{
    public function __construct(
        private readonly SystemSettingsService $systemSettings,
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

        $recentFailure = AutomationExecution::query()
            ->where('status', AutomationExecutionStatus::Failed)
            ->where('created_at', '>=', $checkedAt->copy()->subHours(24))
            ->exists();

        $lastRun = AutomationExecution::query()->latest('created_at')->value('created_at');

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
                'last_execution_at' => \Illuminate\Support\Carbon::parse($lastRun)->toIso8601String(),
                'recent_failure' => $recentFailure,
            ],
        );
    }
}
