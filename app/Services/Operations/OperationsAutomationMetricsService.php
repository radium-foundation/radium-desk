<?php

namespace App\Services\Operations;

use App\Enums\AutomationExecutionStatus;
use App\Models\AutomationExecution;
use Illuminate\Support\Facades\Schema;

class OperationsAutomationMetricsService
{
    /**
     * @return array<string, mixed>
     */
    public function metrics(?OperationsDashboardSnapshot $snapshot = null): array
    {
        if (! Schema::hasTable('automation_executions')) {
            return $this->emptyMetrics();
        }

        $statusCounts = $snapshot?->todayAutomationExecutionCounts()
            ?? $this->statusCountsFromDatabase();
        $executions = $snapshot?->todayAutomationExecutions()
            ?? AutomationExecution::query()
                ->where('created_at', '>=', today())
                ->latest('created_at')
                ->limit(max(1, (int) config('operations.dashboard.automation_execution_limit', 1000)))
                ->get();

        $success = (int) ($statusCounts[AutomationExecutionStatus::Success->value] ?? 0);
        $failed = (int) ($statusCounts[AutomationExecutionStatus::Failed->value] ?? 0);
        $partialSuccess = 0;
        $durations = [];

        foreach ($executions as $execution) {
            if ($execution->started_at !== null && $execution->completed_at !== null) {
                $durations[] = $execution->started_at->diffInMilliseconds($execution->completed_at);
            }

            if ($execution->status === AutomationExecutionStatus::Success && $this->isPartialSuccess($execution)) {
                $partialSuccess++;
            }
        }

        $total = array_sum($statusCounts);
        $averageExecutionMs = $durations !== [] ? (int) round(array_sum($durations) / count($durations)) : null;

        return [
            'executions_today' => $total,
            'success' => max(0, $success - $partialSuccess),
            'partial_success' => $partialSuccess,
            'failed' => $failed,
            'average_execution_ms' => $averageExecutionMs,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function statusCountsFromDatabase(): array
    {
        return AutomationExecution::query()
            ->where('created_at', '>=', today())
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count): int => (int) $count)
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyMetrics(): array
    {
        return [
            'executions_today' => 0,
            'success' => 0,
            'partial_success' => 0,
            'failed' => 0,
            'average_execution_ms' => null,
        ];
    }

    private function isPartialSuccess(AutomationExecution $execution): bool
    {
        $channelResults = $execution->metadata['channel_results'] ?? [];

        if (! is_array($channelResults) || $channelResults === []) {
            return false;
        }

        $sent = 0;
        $failed = 0;

        foreach ($channelResults as $result) {
            if (! is_array($result)) {
                continue;
            }

            $success = (bool) ($result['success'] ?? false);
            $metadata = is_array($result['metadata'] ?? null) ? $result['metadata'] : [];
            $status = (string) ($metadata['status'] ?? '');

            if ($status === 'not_yet_configured') {
                continue;
            }

            if ($success) {
                $sent++;
            } else {
                $failed++;
            }
        }

        return $sent > 0 && $failed > 0;
    }
}
