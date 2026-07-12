<?php

namespace App\Services\Operations;

use App\Enums\AutomationExecutionStatus;
use App\Models\AutomationExecution;
use App\Models\Incident;
use App\Support\Operations\AutomationExecutionClassifier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class AutomationHealthService
{
    public function __construct(
        private readonly AutomationExecutionClassifier $classifier,
        private readonly AutomationHealthStatusCalculator $healthCalculator,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function dashboardData(array $filters = []): array
    {
        if (! Schema::hasTable('automation_executions')) {
            return $this->emptyDashboard();
        }

        $overview = $this->overviewMetrics();
        $health = $this->healthCalculator->calculate(
            lastSuccessAt: $overview['last_success_at'],
            lastExecutionAt: $overview['last_execution_at'],
            failuresToday: $overview['failures_today'],
            pendingCount: $overview['pending_executions'],
            oldestPendingStartedAt: $overview['oldest_pending_started_at'],
        );

        return [
            'overview' => array_merge($overview, [
                'health_status' => $health['status']->value,
                'health_label' => $health['label'],
                'health_badge_class' => $health['badge_class'],
                'health_detail' => $health['detail'],
            ]),
            'breakdown' => $this->breakdownByType(),
            'activity' => $this->paginatedActivity($filters),
            'failures' => $this->recentFailures(),
            'filter_options' => [
                'automation_types' => $this->classifier->typeOptions(),
                'statuses' => [
                    AutomationExecutionStatus::Pending->value => 'Pending',
                    AutomationExecutionStatus::Success->value => 'Success',
                    AutomationExecutionStatus::Failed->value => 'Failed',
                    AutomationExecutionStatus::Skipped->value => 'Skipped',
                ],
            ],
            'filters' => $this->normalizedFilters($filters),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function executionDetail(AutomationExecution $execution): array
    {
        $execution->loadMissing(['waitingState.incident.order', 'supportAppointment.incident.order']);

        $incident = $execution->waitingState?->incident
            ?? $execution->supportAppointment?->incident;
        $durationMs = null;

        if ($execution->started_at !== null && $execution->completed_at !== null) {
            $durationMs = $execution->started_at->diffInMilliseconds($execution->completed_at);
        }

        return [
            'id' => $execution->id,
            'policy_key' => $execution->policy_key,
            'policy_label' => $this->policyLabel($execution),
            'automation_type' => $this->classifier->typeFor($execution),
            'automation_label' => $this->classifier->label($this->classifier->typeFor($execution)),
            'action_type' => $execution->action_type?->value,
            'action_key' => $execution->action_key,
            'action_label' => $this->actionLabel($execution),
            'channel' => $execution->channel,
            'status' => $execution->status->value,
            'status_label' => ucfirst($execution->status->value),
            'subject' => $this->subjectLabel($execution, $incident),
            'incident_reference' => $incident instanceof Incident ? $incident->display_reference : null,
            'incident_url' => $incident instanceof Incident ? route('incidents.show', $incident) : null,
            'order_id' => $incident?->order?->order_id,
            'customer_name' => $incident?->order?->customer_name,
            'metadata' => $execution->metadata ?? [],
            'started_at' => $execution->started_at?->toIso8601String(),
            'started_at_display' => $execution->started_at !== null
                ? display_app_datetime_seconds($execution->started_at)
                : null,
            'completed_at' => $execution->completed_at?->toIso8601String(),
            'completed_at_display' => $execution->completed_at !== null
                ? display_app_datetime_seconds($execution->completed_at)
                : null,
            'created_at_display' => display_app_datetime_seconds($execution->created_at),
            'duration_ms' => $durationMs,
            'duration_display' => $this->formatDuration($durationMs),
            'result' => $execution->status->value,
            'error_message' => $execution->error_message,
            'triggered_by' => $this->triggeredByLabel($execution),
            'retry_status' => $this->retryStatusLabel($execution),
            'idempotency_key' => $execution->idempotency_key,
            'external_id' => $execution->external_id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function overviewMetrics(): array
    {
        $today = today();

        $statusCounts = AutomationExecution::query()
            ->where('created_at', '>=', $today)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count): int => (int) $count)
            ->all();

        $lastSuccessAt = AutomationExecution::query()
            ->where('status', AutomationExecutionStatus::Success)
            ->latest('completed_at')
            ->value('completed_at');

        $lastFailedAt = AutomationExecution::query()
            ->where('status', AutomationExecutionStatus::Failed)
            ->latest('completed_at')
            ->value('completed_at');

        $lastExecutionAt = AutomationExecution::query()
            ->latest('created_at')
            ->value('created_at');

        $pendingCount = (int) AutomationExecution::query()
            ->where('status', AutomationExecutionStatus::Pending)
            ->count();

        $oldestPendingStartedAt = AutomationExecution::query()
            ->where('status', AutomationExecutionStatus::Pending)
            ->oldest('started_at')
            ->value('started_at');

        $durations = AutomationExecution::query()
            ->where('created_at', '>=', $today)
            ->whereNotNull('started_at')
            ->whereNotNull('completed_at')
            ->get(['started_at', 'completed_at'])
            ->map(fn (AutomationExecution $execution): int => $execution->started_at->diffInMilliseconds($execution->completed_at));

        $averageExecutionMs = $durations->isNotEmpty()
            ? (int) round($durations->avg())
            : null;

        return [
            'last_success_at' => $lastSuccessAt instanceof Carbon ? $lastSuccessAt : null,
            'last_success_display' => $lastSuccessAt !== null ? display_app_datetime_seconds($lastSuccessAt) : '—',
            'last_failed_at' => $lastFailedAt instanceof Carbon ? $lastFailedAt : null,
            'last_failed_display' => $lastFailedAt !== null ? display_app_datetime_seconds($lastFailedAt) : '—',
            'last_execution_at' => $lastExecutionAt instanceof Carbon ? $lastExecutionAt : null,
            'executions_today' => array_sum($statusCounts),
            'failures_today' => (int) ($statusCounts[AutomationExecutionStatus::Failed->value] ?? 0),
            'pending_executions' => $pendingCount,
            'oldest_pending_started_at' => $oldestPendingStartedAt instanceof Carbon ? $oldestPendingStartedAt : null,
            'average_execution_ms' => $averageExecutionMs,
            'average_execution_display' => $this->formatDuration($averageExecutionMs),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function breakdownByType(): array
    {
        $today = today();
        $types = $this->classifier->types();
        $grouped = [];

        foreach ($types as $type) {
            $grouped[$type] = [
                'type' => $type,
                'label' => $this->classifier->label($type),
                'executed_today' => 0,
                'succeeded' => 0,
                'failed' => 0,
                'last_execution_at' => null,
                'last_execution_display' => '—',
            ];
        }

        $todayExecutions = AutomationExecution::query()
            ->where('created_at', '>=', $today)
            ->get(['id', 'policy_key', 'waiting_state_id', 'action_type', 'status', 'created_at']);

        foreach ($todayExecutions as $execution) {
            $type = $this->classifier->typeFor($execution);
            $grouped[$type]['executed_today']++;

            if ($execution->status === AutomationExecutionStatus::Success) {
                $grouped[$type]['succeeded']++;
            }

            if ($execution->status === AutomationExecutionStatus::Failed) {
                $grouped[$type]['failed']++;
            }
        }

        $lastByType = AutomationExecution::query()
            ->latest('created_at')
            ->limit(500)
            ->get(['id', 'policy_key', 'waiting_state_id', 'action_type', 'created_at']);

        foreach ($lastByType as $execution) {
            $type = $this->classifier->typeFor($execution);

            if ($grouped[$type]['last_execution_at'] === null) {
                $grouped[$type]['last_execution_at'] = $execution->created_at;
                $grouped[$type]['last_execution_display'] = display_app_datetime_seconds($execution->created_at);
            }
        }

        return array_values($grouped);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginatedActivity(array $filters = []): LengthAwarePaginator
    {
        $perPage = max(1, (int) config('operations.automation_health.activity_per_page', 50));
        $normalized = $this->normalizedFilters($filters);

        return $this->baseQuery()
            ->when($normalized['automation_type'], fn (Builder $query, string $type): Builder => $this->applyAutomationTypeFilter($query, $type))
            ->when($normalized['status'], fn (Builder $query, string $status): Builder => $query->where('status', $status))
            ->when($normalized['date'], fn (Builder $query, string $date): Builder => $query->whereDate('created_at', $date))
            ->when($normalized['search'], fn (Builder $query, string $search): Builder => $this->applySearchFilter($query, $search))
            ->latest('created_at')
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (AutomationExecution $execution): array => $this->mapActivityRow($execution));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentFailures(): array
    {
        $limit = max(1, (int) config('operations.automation_health.failures_limit', 50));

        return $this->baseQuery()
            ->where('status', AutomationExecutionStatus::Failed)
            ->latest('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (AutomationExecution $execution): array => $this->mapFailureRow($execution))
            ->all();
    }

    private function baseQuery(): Builder
    {
        return AutomationExecution::query()
            ->with(['waitingState.incident.order', 'supportAppointment.incident.order']);
    }

    /**
     * @return array<string, mixed>
     */
    private function mapActivityRow(AutomationExecution $execution): array
    {
        $incident = $execution->waitingState?->incident
            ?? $execution->supportAppointment?->incident;
        $durationMs = null;

        if ($execution->started_at !== null && $execution->completed_at !== null) {
            $durationMs = $execution->started_at->diffInMilliseconds($execution->completed_at);
        }

        return [
            'id' => $execution->id,
            'timestamp' => $execution->created_at,
            'timestamp_display' => display_app_datetime_seconds($execution->created_at),
            'automation_type' => $this->classifier->typeFor($execution),
            'automation_label' => $this->classifier->label($this->classifier->typeFor($execution)),
            'action_label' => $this->actionLabel($execution),
            'subject' => $this->subjectLabel($execution, $incident),
            'status' => $execution->status->value,
            'status_label' => ucfirst($execution->status->value),
            'duration_ms' => $durationMs,
            'duration_display' => $this->formatDuration($durationMs),
            'triggered_by' => $this->triggeredByLabel($execution),
            'detail_url' => route('admin.operations.automation-health.executions.show', $execution),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapFailureRow(AutomationExecution $execution): array
    {
        $incident = $execution->waitingState?->incident
            ?? $execution->supportAppointment?->incident;

        return [
            'id' => $execution->id,
            'timestamp_display' => display_app_datetime_seconds($execution->created_at),
            'automation_label' => $this->classifier->label($this->classifier->typeFor($execution)),
            'subject' => $this->subjectLabel($execution, $incident),
            'error_message' => $execution->error_message ?? 'Unknown error',
            'retry_status' => $this->retryStatusLabel($execution),
            'detail_url' => route('admin.operations.automation-health.executions.show', $execution),
        ];
    }

    private function applyAutomationTypeFilter(Builder $query, string $type): Builder
    {
        return match ($type) {
            AutomationExecutionClassifier::TYPE_APPOINTMENT_REMINDER => $query->where('policy_key', 'appointment-reminder'),
            AutomationExecutionClassifier::TYPE_WAITING_LIFECYCLE => $query->whereNotNull('waiting_state_id'),
            AutomationExecutionClassifier::TYPE_COMMUNICATION_ACTION => $query->where('policy_key', 'like', 'communication%'),
            AutomationExecutionClassifier::TYPE_FUTURE_AI => $query
                ->where(function (Builder $inner): void {
                    $inner->whereNull('policy_key')
                        ->orWhere('policy_key', '!=', 'appointment-reminder');
                })
                ->whereNull('waiting_state_id')
                ->where(function (Builder $inner): void {
                    $inner->whereNull('policy_key')
                        ->orWhere('policy_key', 'not like', 'communication%');
                }),
            default => $query,
        };
    }

    private function applySearchFilter(Builder $query, string $search): Builder
    {
        $term = trim($search);

        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $inner) use ($term): void {
            if (ctype_digit($term)) {
                $inner->where('automation_executions.id', (int) $term);
            }

            $inner->orWhere('idempotency_key', 'like', "%{$term}%")
                ->orWhereHas('waitingState.incident', fn (Builder $incidentQuery) => $this->applyIncidentSearch($incidentQuery, $term))
                ->orWhereHas('waitingState.incident.order', fn (Builder $orderQuery) => $this->applyOrderSearch($orderQuery, $term))
                ->orWhereHas('supportAppointment.incident', fn (Builder $incidentQuery) => $this->applyIncidentSearch($incidentQuery, $term))
                ->orWhereHas('supportAppointment.incident.order', fn (Builder $orderQuery) => $this->applyOrderSearch($orderQuery, $term))
                ->orWhereHas('supportAppointment', fn (Builder $appointmentQuery) => $appointmentQuery->where('id', $term));
        });
    }

    private function applyIncidentSearch(Builder $query, string $term): void
    {
        $query->where('reference_no', 'like', "%{$term}%")
            ->orWhere('id', $term);
    }

    private function applyOrderSearch(Builder $query, string $term): void
    {
        $query->where('order_id', 'like', "%{$term}%")
            ->orWhere('customer_name', 'like', "%{$term}%");
    }

    private function policyLabel(AutomationExecution $execution): string
    {
        if ($execution->policy_key === 'appointment-reminder') {
            return 'Appointment Reminder';
        }

        return str($execution->policy_key)->replace(['_', '-'], ' ')->title()->toString();
    }

    private function actionLabel(AutomationExecution $execution): string
    {
        $parts = array_filter([
            $execution->action_type?->value,
            $execution->action_key,
            $execution->schedule_step !== null ? "step {$execution->schedule_step}" : null,
        ]);

        return $parts !== [] ? implode(' · ', $parts) : '—';
    }

    private function subjectLabel(AutomationExecution $execution, ?Incident $incident): string
    {
        if ($incident instanceof Incident) {
            $orderId = $incident->order?->order_id;
            $customer = $incident->order?->customer_name;

            return collect([
                $incident->display_reference,
                $orderId,
                $customer,
            ])->filter()->implode(' · ');
        }

        if ($execution->support_appointment_id !== null) {
            return "Appointment {$execution->support_appointment_id}";
        }

        return "Execution {$execution->id}";
    }

    private function triggeredByLabel(AutomationExecution $execution): string
    {
        if ($execution->policy_key === 'appointment-reminder') {
            return 'Scheduler · team-telegram:send-appointment-reminders';
        }

        if ($execution->waiting_state_id !== null) {
            return 'Scheduler · waiting lifecycle';
        }

        $trigger = $execution->metadata['triggered_by'] ?? $execution->metadata['trigger'] ?? null;

        if (is_string($trigger) && $trigger !== '') {
            return $trigger;
        }

        return 'System';
    }

    private function retryStatusLabel(AutomationExecution $execution): string
    {
        return match ($execution->status) {
            AutomationExecutionStatus::Failed => 'Will retry on next scheduler run',
            AutomationExecutionStatus::Pending => $execution->started_at !== null && $execution->started_at->lt(now()->subHour())
                ? 'Pending — stale'
                : 'Pending',
            AutomationExecutionStatus::Skipped => 'Skipped — no retry',
            AutomationExecutionStatus::Success => 'Completed',
        };
    }

    private function formatDuration(?int $durationMs): string
    {
        if ($durationMs === null) {
            return '—';
        }

        if ($durationMs < 1000) {
            return "{$durationMs} ms";
        }

        return number_format($durationMs / 1000, 2).' s';
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{automation_type: ?string, status: ?string, date: ?string, search: ?string}
     */
    private function normalizedFilters(array $filters): array
    {
        $automationType = $filters['automation_type'] ?? null;
        $status = $filters['status'] ?? null;
        $date = $filters['date'] ?? null;
        $search = $filters['search'] ?? null;

        return [
            'automation_type' => is_string($automationType) && $automationType !== '' ? $automationType : null,
            'status' => is_string($status) && $status !== '' ? $status : null,
            'date' => is_string($date) && $date !== '' ? $date : null,
            'search' => is_string($search) && trim($search) !== '' ? trim($search) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyDashboard(): array
    {
        $health = $this->healthCalculator->calculate(null, null, 0, 0, null);

        return [
            'overview' => [
                'health_status' => $health['status']->value,
                'health_label' => $health['label'],
                'health_badge_class' => $health['badge_class'],
                'health_detail' => 'Execution table unavailable.',
                'last_success_display' => '—',
                'last_failed_display' => '—',
                'executions_today' => 0,
                'failures_today' => 0,
                'pending_executions' => 0,
                'average_execution_display' => '—',
            ],
            'breakdown' => array_map(
                fn (string $type): array => [
                    'type' => $type,
                    'label' => $this->classifier->label($type),
                    'executed_today' => 0,
                    'succeeded' => 0,
                    'failed' => 0,
                    'last_execution_display' => '—',
                ],
                $this->classifier->types(),
            ),
            'activity' => new Paginator([], 0, max(1, (int) config('operations.automation_health.activity_per_page', 50))),
            'failures' => [],
            'filter_options' => [
                'automation_types' => $this->classifier->typeOptions(),
                'statuses' => [],
            ],
            'filters' => [
                'automation_type' => null,
                'status' => null,
                'date' => null,
                'search' => null,
            ],
        ];
    }
}
