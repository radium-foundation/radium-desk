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

        $executions = $snapshot?->todayAutomationExecutions()
            ?? AutomationExecution::query()->where('created_at', '>=', today())->get();

        $success = 0;
        $partialSuccess = 0;
        $failed = 0;
        $durations = [];

        foreach ($executions as $execution) {
            if ($execution->started_at !== null && $execution->completed_at !== null) {
                $durations[] = $execution->started_at->diffInMilliseconds($execution->completed_at);
            }

            if ($execution->status === AutomationExecutionStatus::Failed) {
                $failed++;

                continue;
            }

            if ($execution->status === AutomationExecutionStatus::Success && $this->isPartialSuccess($execution)) {
                $partialSuccess++;

                continue;
            }

            if ($execution->status === AutomationExecutionStatus::Success) {
                $success++;
            }
        }

        $total = $executions->count();
        $averageExecutionMs = $durations !== [] ? (int) round(array_sum($durations) / count($durations)) : null;

        return [
            'executions_today' => $total,
            'success' => $success,
            'partial_success' => $partialSuccess,
            'failed' => $failed,
            'average_execution_ms' => $averageExecutionMs,
        ];
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
