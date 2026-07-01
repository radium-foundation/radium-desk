<?php

namespace App\Services\Automation;

use App\Data\Automation\AutomationRuntimeResult;
use App\Data\Automation\AutomationSchedulerRunResult;
use App\Enums\AutomationExecutionStatus;
use App\Exceptions\InvalidAutomationPolicyException;
use App\Exceptions\UnknownAutomationPolicyException;
use App\Models\IncidentWaitingState;
use App\Services\AutomationPolicyService;
use App\Services\SystemSettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class AutomationSchedulerService
{
    public function __construct(
        private readonly SystemSettingsService $systemSettings,
        private readonly WaitingStateScanner $waitingStateScanner,
        private readonly AutomationPolicyService $automationPolicyService,
        private readonly ExecutionPlanner $executionPlanner,
        private readonly AutomationRuntime $automationRuntime,
    ) {}

    public function run(?Carbon $referenceAt = null, int $chunkSize = 100): AutomationSchedulerRunResult
    {
        if (! $this->systemSettings->getBool('automation.scheduler.enabled', false)) {
            Log::info('Automation scheduler skipped because it is disabled.');

            return AutomationSchedulerRunResult::disabled();
        }

        $referenceAt ??= Carbon::now();

        $waitingStatesScanned = 0;
        $dueActionsFound = 0;
        $executed = 0;
        $skipped = 0;
        $failures = 0;

        $this->waitingStateScanner->scanActive(
            function (IncidentWaitingState $waitingState) use (
                $referenceAt,
                &$waitingStatesScanned,
                &$dueActionsFound,
                &$executed,
                &$skipped,
                &$failures,
            ): void {
                $waitingStatesScanned++;

                try {
                    $dueActions = $this->automationPolicyService->dueActions($waitingState, $referenceAt);
                } catch (UnknownAutomationPolicyException|InvalidAutomationPolicyException $exception) {
                    Log::warning('Automation scheduler skipped waiting state due to policy resolution failure.', [
                        'waiting_state_id' => $waitingState->id,
                        'policy_key' => $waitingState->reminder_policy_key,
                        'error' => $exception->getMessage(),
                    ]);

                    return;
                } catch (Throwable $exception) {
                    Log::error('Automation scheduler encountered an unexpected policy error.', [
                        'waiting_state_id' => $waitingState->id,
                        'policy_key' => $waitingState->reminder_policy_key,
                        'error' => $exception->getMessage(),
                    ]);

                    return;
                }

                if ($dueActions === []) {
                    return;
                }

                $dueActionsFound += count($dueActions);

                $plannedActions = $this->executionPlanner->plan($waitingState, $dueActions);

                try {
                    $runtimeResult = $this->automationRuntime->execute($waitingState, $plannedActions);
                } catch (Throwable $exception) {
                    Log::error('Automation scheduler runtime execution failed.', [
                        'waiting_state_id' => $waitingState->id,
                        'error' => $exception->getMessage(),
                    ]);

                    return;
                }

                [$executedDelta, $skippedDelta, $failuresDelta] = $this->countRuntimeResults($runtimeResult);
                $executed += $executedDelta;
                $skipped += $skippedDelta;
                $failures += $failuresDelta;
            },
            $chunkSize,
        );

        $result = new AutomationSchedulerRunResult(
            enabled: true,
            waitingStatesScanned: $waitingStatesScanned,
            dueActionsFound: $dueActionsFound,
            executed: $executed,
            skipped: $skipped,
            failures: $failures,
        );

        Log::info('Automation scheduler run completed.', $result->toLogContext());

        return $result;
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function countRuntimeResults(AutomationRuntimeResult $runtimeResult): array
    {
        $executed = 0;
        $skipped = 0;
        $failures = 0;

        foreach ($runtimeResult->results as $executionResult) {
            if ($executionResult->wasSkipped()) {
                $skipped++;

                continue;
            }

            if ($executionResult->status === AutomationExecutionStatus::Success) {
                $executed++;

                continue;
            }

            if ($executionResult->status === AutomationExecutionStatus::Failed) {
                $failures++;
            }
        }

        return [$executed, $skipped, $failures];
    }
}
