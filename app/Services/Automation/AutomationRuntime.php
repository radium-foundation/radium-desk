<?php

namespace App\Services\Automation;

use App\Contracts\Automation\ActionHandler;
use App\Data\Automation\AutomationExecutionResult;
use App\Data\Automation\AutomationRuntimeResult;
use App\Data\Automation\PlannedAutomationAction;
use App\Enums\AutomationExecutionStatus;
use App\Enums\AutomationPolicyActionType;
use App\Models\AutomationExecution;
use App\Models\IncidentWaitingState;
use Illuminate\Support\Carbon;

class AutomationRuntime
{
    /**
     * @param  array<int, ActionHandler>  $handlers
     */
    public function __construct(
        private readonly AutomationIdempotencyKeyGenerator $idempotencyKeyGenerator,
        private readonly array $handlers,
    ) {}

    /**
     * @param  list<PlannedAutomationAction>  $plannedActions
     */
    public function execute(IncidentWaitingState $waitingState, array $plannedActions): AutomationRuntimeResult
    {
        $results = [];

        foreach ($plannedActions as $plannedAction) {
            $results[] = $this->executePlannedAction($plannedAction);
        }

        return AutomationRuntimeResult::fromResults($results);
    }

    private function executePlannedAction(PlannedAutomationAction $plannedAction): AutomationExecutionResult
    {
        $idempotencyKey = $this->idempotencyKeyGenerator->generate(
            waitingStateId: $plannedAction->waitingState->id,
            policyKey: $plannedAction->policyKey,
            scheduleStep: $plannedAction->scheduleStep,
            actionType: $plannedAction->actionType,
            channel: $plannedAction->channel,
        );

        $existing = AutomationExecution::query()
            ->where('idempotency_key', $idempotencyKey)
            ->where('status', AutomationExecutionStatus::Success)
            ->first();

        if ($existing !== null) {
            return new AutomationExecutionResult(
                execution: $existing,
                status: AutomationExecutionStatus::Skipped,
                skippedExisting: true,
            );
        }

        $handler = $this->resolveHandler($plannedAction->actionType);

        if ($handler === null) {
            $execution = $this->createExecutionRecord(
                plannedAction: $plannedAction,
                idempotencyKey: $idempotencyKey,
                status: AutomationExecutionStatus::Skipped,
                errorMessage: 'No action handler is registered for this action type.',
            );

            return new AutomationExecutionResult(
                execution: $execution,
                status: AutomationExecutionStatus::Skipped,
            );
        }

        $execution = $this->createExecutionRecord(
            plannedAction: $plannedAction,
            idempotencyKey: $idempotencyKey,
            status: AutomationExecutionStatus::Pending,
        );

        $handlerResult = $handler->handle($plannedAction);

        if ($handlerResult->success) {
            $execution->update([
                'status' => AutomationExecutionStatus::Success,
                'external_id' => $handlerResult->externalId,
                'error_message' => null,
                'metadata' => $handlerResult->metadata,
                'completed_at' => Carbon::now(),
            ]);

            return new AutomationExecutionResult(
                execution: $execution->fresh(),
                status: AutomationExecutionStatus::Success,
            );
        }

        $execution->update([
            'status' => AutomationExecutionStatus::Failed,
            'external_id' => $handlerResult->externalId,
            'error_message' => $handlerResult->errorMessage,
            'metadata' => $handlerResult->metadata,
            'completed_at' => Carbon::now(),
        ]);

        return new AutomationExecutionResult(
            execution: $execution->fresh(),
            status: AutomationExecutionStatus::Failed,
        );
    }

    private function resolveHandler(AutomationPolicyActionType $actionType): ?ActionHandler
    {
        foreach ($this->handlers as $handler) {
            if ($handler->supports($actionType)) {
                return $handler;
            }
        }

        return null;
    }

    private function createExecutionRecord(
        PlannedAutomationAction $plannedAction,
        string $idempotencyKey,
        AutomationExecutionStatus $status,
        ?string $errorMessage = null,
        ?string $externalId = null,
        array $metadata = [],
    ): AutomationExecution {
        $now = Carbon::now();

        return AutomationExecution::query()->create([
            'waiting_state_id' => $plannedAction->waitingState->id,
            'policy_key' => $plannedAction->policyKey,
            'schedule_step' => $plannedAction->scheduleStep,
            'action_type' => $plannedAction->actionType,
            'action_key' => $plannedAction->actionKey,
            'channel' => $plannedAction->channel,
            'status' => $status,
            'idempotency_key' => $idempotencyKey,
            'external_id' => $externalId,
            'error_message' => $errorMessage,
            'metadata' => $metadata === [] ? null : $metadata,
            'started_at' => $now,
            'completed_at' => $status === AutomationExecutionStatus::Pending ? null : $now,
        ]);
    }
}
