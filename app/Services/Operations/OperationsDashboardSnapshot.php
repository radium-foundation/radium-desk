<?php

namespace App\Services\Operations;

use App\Enums\AutomationExecutionStatus;
use App\Enums\InteraktMessageDirection;
use App\Infrastructure\IntegrationHealth\IntegrationHealthSnapshot;
use App\Infrastructure\IntegrationHealth\Probes\CashfreeIntegrationHealthProbe;
use App\Infrastructure\Queue\QueueMetricsService;
use App\Infrastructure\Queue\QueueMetricsSnapshot;
use App\Models\AuditLog;
use App\Models\AutomationExecution;
use App\Models\InteraktMessage;
use App\Models\OutboxEvent;
use App\Enums\OutboxEventStatus;
use App\Services\Notifications\NotificationAuditTrailService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OperationsDashboardSnapshot
{
    /** @var Collection<int, AuditLog>|null */
    private ?Collection $todayNotificationAuditLogs = null;

    private ?int $todayNotificationDispatchCount = null;

    private ?OperationsAuditAggregator $auditAggregator = null;

    /** @var Collection<int, AutomationExecution>|null */
    private ?Collection $todayAutomationExecutions = null;

    /** @var array<string, int>|null */
    private ?array $todayAutomationExecutionCounts = null;

    private ?bool $recentAutomationFailure = null;

    private ?bool $recentAutomationSuccess = null;

    private ?Carbon $lastAutomationExecutionAt = null;

    private bool $lastAutomationExecutionAtLoaded = false;

    private ?QueueMetricsSnapshot $queueSnapshot = null;

    private ?int $pendingJobsCount = null;

    private ?int $runningJobsCount = null;

    private ?int $retriesCount = null;

    private ?IntegrationHealthSnapshot $cashfreeSnapshot = null;

    /** @var array{last_success_at: mixed, recent_failures: int, has_recent_activity: bool}|null */
    private ?array $interaktInputs = null;

    public function __construct(
        private readonly QueueMetricsService $queueMetricsService,
        private readonly CashfreeIntegrationHealthProbe $cashfreeProbe,
    ) {}

    public static function load(
        QueueMetricsService $queueMetricsService,
        CashfreeIntegrationHealthProbe $cashfreeProbe,
    ): self {
        return new self($queueMetricsService, $cashfreeProbe);
    }

    public function todayNotificationDispatchCount(): int
    {
        if ($this->todayNotificationDispatchCount !== null) {
            return $this->todayNotificationDispatchCount;
        }

        if (! Schema::hasTable('audit_logs')) {
            return $this->todayNotificationDispatchCount = 0;
        }

        return $this->todayNotificationDispatchCount = (int) AuditLog::query()
            ->where('event', NotificationAuditTrailService::EVENT_DISPATCHED)
            ->where('created_at', '>=', today())
            ->count();
    }

    /**
     * @return Collection<int, AuditLog>
     */
    public function todayNotificationAuditLogs(): Collection
    {
        if ($this->todayNotificationAuditLogs !== null) {
            return $this->todayNotificationAuditLogs;
        }

        if (! Schema::hasTable('audit_logs')) {
            return $this->todayNotificationAuditLogs = collect();
        }

        $limit = max(1, (int) config('operations.dashboard.audit_log_limit', 2000));

        return $this->todayNotificationAuditLogs = AuditLog::query()
            ->where('event', NotificationAuditTrailService::EVENT_DISPATCHED)
            ->where('created_at', '>=', today())
            ->latest('created_at')
            ->limit($limit)
            ->get();
    }

    public function auditAggregator(): OperationsAuditAggregator
    {
        if ($this->auditAggregator !== null) {
            return $this->auditAggregator;
        }

        return $this->auditAggregator = new OperationsAuditAggregator(
            $this->todayNotificationAuditLogs(),
            $this->todayNotificationDispatchCount(),
        );
    }

    /**
     * @return Collection<int, AutomationExecution>
     */
    public function todayAutomationExecutions(): Collection
    {
        if ($this->todayAutomationExecutions !== null) {
            return $this->todayAutomationExecutions;
        }

        if (! Schema::hasTable('automation_executions')) {
            return $this->todayAutomationExecutions = collect();
        }

        $limit = max(1, (int) config('operations.dashboard.automation_execution_limit', 1000));

        return $this->todayAutomationExecutions = AutomationExecution::query()
            ->where('created_at', '>=', today())
            ->latest('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * @return array<string, int>
     */
    public function todayAutomationExecutionCounts(): array
    {
        if ($this->todayAutomationExecutionCounts !== null) {
            return $this->todayAutomationExecutionCounts;
        }

        if (! Schema::hasTable('automation_executions')) {
            return $this->todayAutomationExecutionCounts = [];
        }

        return $this->todayAutomationExecutionCounts = AutomationExecution::query()
            ->where('created_at', '>=', today())
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count): int => (int) $count)
            ->all();
    }

    public function hasRecentAutomationFailure(): bool
    {
        if ($this->recentAutomationFailure !== null) {
            return $this->recentAutomationFailure;
        }

        if (! Schema::hasTable('automation_executions')) {
            return $this->recentAutomationFailure = false;
        }

        return $this->recentAutomationFailure = AutomationExecution::query()
            ->where('status', AutomationExecutionStatus::Failed)
            ->where('created_at', '>=', now()->subHours(24))
            ->exists();
    }

    public function hasRecentAutomationSuccess(): bool
    {
        if ($this->recentAutomationSuccess !== null) {
            return $this->recentAutomationSuccess;
        }

        if (! Schema::hasTable('automation_executions')) {
            return $this->recentAutomationSuccess = false;
        }

        return $this->recentAutomationSuccess = AutomationExecution::query()
            ->where('status', AutomationExecutionStatus::Success)
            ->where('created_at', '>=', now()->subDays(7))
            ->exists();
    }

    public function lastAutomationExecutionAt(): ?Carbon
    {
        if ($this->lastAutomationExecutionAtLoaded || ! Schema::hasTable('automation_executions')) {
            return $this->lastAutomationExecutionAt;
        }

        $this->lastAutomationExecutionAtLoaded = true;
        $lastRun = AutomationExecution::query()->latest('created_at')->value('created_at');

        return $this->lastAutomationExecutionAt = $lastRun instanceof Carbon ? $lastRun : null;
    }

    public function queueSnapshot(): QueueMetricsSnapshot
    {
        if ($this->queueSnapshot !== null) {
            return $this->queueSnapshot;
        }

        return $this->queueSnapshot = $this->queueMetricsService->latest()
            ?? $this->queueMetricsService->capture();
    }

    public function pendingJobsCount(): int
    {
        if ($this->pendingJobsCount !== null) {
            return $this->pendingJobsCount;
        }

        if (! Schema::hasTable('jobs')) {
            return $this->pendingJobsCount = 0;
        }

        return $this->pendingJobsCount = (int) DB::table('jobs')->whereNull('reserved_at')->count();
    }

    public function runningJobsCount(): int
    {
        if ($this->runningJobsCount !== null) {
            return $this->runningJobsCount;
        }

        if (! Schema::hasTable('jobs')) {
            return $this->runningJobsCount = 0;
        }

        return $this->runningJobsCount = (int) DB::table('jobs')->whereNotNull('reserved_at')->count();
    }

    public function retriesCount(): int
    {
        if ($this->retriesCount !== null) {
            return $this->retriesCount;
        }

        $jobRetries = 0;

        if (Schema::hasTable('jobs')) {
            $jobRetries = (int) DB::table('jobs')->where('attempts', '>', 1)->count();
        }

        $outboxRetries = 0;

        if (Schema::hasTable('outbox_events')) {
            $outboxRetries = (int) OutboxEvent::query()
                ->whereIn('status', [OutboxEventStatus::Pending, OutboxEventStatus::Processing, OutboxEventStatus::Failed])
                ->where('attempts', '>', 0)
                ->count();
        }

        return $this->retriesCount = $jobRetries + $outboxRetries;
    }

    public function cashfreeIntegrationSnapshot(): IntegrationHealthSnapshot
    {
        if ($this->cashfreeSnapshot !== null) {
            return $this->cashfreeSnapshot;
        }

        return $this->cashfreeSnapshot = $this->cashfreeProbe->probe();
    }

    /**
     * @return array{last_success_at: mixed, recent_failures: int, has_recent_activity: bool}
     */
    public function interaktInputs(): array
    {
        if ($this->interaktInputs !== null) {
            return $this->interaktInputs;
        }

        if (! Schema::hasTable('interakt_messages')) {
            return $this->interaktInputs = [
                'last_success_at' => null,
                'recent_failures' => 0,
                'has_recent_activity' => false,
            ];
        }

        $lastSuccess = InteraktMessage::query()
            ->where('direction', InteraktMessageDirection::Outgoing)
            ->whereNotNull('sent_at')
            ->latest('sent_at')
            ->value('sent_at');

        $recentFailures = (int) InteraktMessage::query()
            ->where('direction', InteraktMessageDirection::Outgoing)
            ->where('created_at', '>=', now()->subDay())
            ->whereNotNull('channel_failure_reason')
            ->count();

        $hasRecentActivity = InteraktMessage::query()
            ->where('created_at', '>=', now()->subDays(30))
            ->exists();

        return $this->interaktInputs = [
            'last_success_at' => $lastSuccess,
            'recent_failures' => $recentFailures,
            'has_recent_activity' => $hasRecentActivity,
        ];
    }
}
