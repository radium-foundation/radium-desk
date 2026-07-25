<?php

namespace App\Services\Operations;

use App\Enums\AutomationExecutionStatus;
use App\Models\AutomationExecution;
use App\ReadModels\Automation\AutomationExecutionReadModel;
use Illuminate\Support\Facades\Schema;

class OperationsAutomationMetricsService
{
    public function __construct(
        private readonly AutomationExecutionReadModel $executionReadModel,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function metrics(?OperationsDashboardSnapshot $snapshot = null): array
    {
        if (! Schema::hasTable('automation_executions')) {
            return $this->emptyMetrics();
        }

        $shared = $this->executionReadModel->metrics();

        // Ops-only partial_success heuristic — not part of the shared ledger KPIs.
        $executions = $snapshot?->todayAutomationExecutions()
            ?? AutomationExecution::query()
                ->where('created_at', '>=', today())
                ->latest('created_at')
                ->limit(max(1, (int) config('operations.dashboard.automation_execution_limit', 1000)))
                ->get();

        $partialSuccess = 0;

        foreach ($executions as $execution) {
            if ($execution->status === AutomationExecutionStatus::Success && $this->isPartialSuccess($execution)) {
                $partialSuccess++;
            }
        }

        return [
            'executions_today' => $shared->executionsToday,
            'success' => max(0, $shared->successToday - $partialSuccess),
            'partial_success' => $partialSuccess,
            'failed' => $shared->failuresToday,
            'average_execution_ms' => $shared->averageExecutionMs,
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
