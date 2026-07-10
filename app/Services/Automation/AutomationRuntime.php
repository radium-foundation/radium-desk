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
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

class AutomationRuntime
{
    private const PENDING_STALE_AFTER_SECONDS = 3600;

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
            ->first();

        if ($existing !== null) {
            return $this->handleExistingExecution($plannedAction, $existing);
        }

        $handler = $this->resolveHandler($plannedAction->actionType);

        if ($handler === null) {
            return $this->persistTerminalSkippedExecution($plannedAction, $idempotencyKey);
        }

        try {
            $execution = AutomationExecution::query()->create(
                $this->executionAttributes(
                    plannedAction: $plannedAction,
                    idempotencyKey: $idempotencyKey,
                    status: AutomationExecutionStatus::Pending,
                ),
            );
        } catch (QueryException|UniqueConstraintViolationException $exception) {
            if (! $this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            $existing = AutomationExecution::query()
                ->where('idempotency_key', $idempotencyKey)
                ->firstOrFail();

            return $this->handleExistingExecution($plannedAction, $existing);
        }

        return $this->executeHandler($plannedAction, $handler, $execution);
    }

    private function handleExistingExecution(
        PlannedAutomationAction $plannedAction,
        AutomationExecution $existing,
    ): AutomationExecutionResult {
        if ($this->shouldRetryExistingExecution($existing)) {
            return $this->retryExistingExecution($plannedAction, $existing);
        }

        return $this->skipExistingExecution($existing);
    }

    private function skipExistingExecution(AutomationExecution $existing): AutomationExecutionResult
    {
        return new AutomationExecutionResult(
            execution: $existing->fresh(),
            status: AutomationExecutionStatus::Skipped,
            skippedExisting: true,
        );
    }

    private function shouldRetryExistingExecution(AutomationExecution $existing): bool
    {
        return match ($existing->status) {
            AutomationExecutionStatus::Failed => true,
            AutomationExecutionStatus::Pending => $this->isPendingStale($existing),
            AutomationExecutionStatus::Success,
            AutomationExecutionStatus::Skipped => false,
        };
    }

    private function isPendingStale(AutomationExecution $execution): bool
    {
        if ($execution->started_at === null) {
            return true;
        }

        return $execution->started_at->lte(
            Carbon::now()->subSeconds(self::PENDING_STALE_AFTER_SECONDS),
        );
    }

    private function retryExistingExecution(
        PlannedAutomationAction $plannedAction,
        AutomationExecution $existing,
    ): AutomationExecutionResult {
        $handler = $this->resolveHandler($plannedAction->actionType);

        if ($handler === null) {
            return $this->skipExistingExecution($existing);
        }

        $execution = $this->resetExecutionForRetry($existing);

        return $this->executeHandler($plannedAction, $handler, $execution);
    }

    private function resetExecutionForRetry(AutomationExecution $execution): AutomationExecution
    {
        $now = Carbon::now();

        $execution->update([
            'status' => AutomationExecutionStatus::Pending,
            'external_id' => null,
            'error_message' => null,
            'metadata' => null,
            'started_at' => $now,
            'completed_at' => null,
        ]);

        return $execution->fresh();
    }

    private function persistTerminalSkippedExecution(
        PlannedAutomationAction $plannedAction,
        string $idempotencyKey,
    ): AutomationExecutionResult {
        $execution = AutomationExecution::query()->firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            $this->executionAttributes(
                plannedAction: $plannedAction,
                idempotencyKey: $idempotencyKey,
                status: AutomationExecutionStatus::Skipped,
                errorMessage: 'No action handler is registered for this action type.',
            ),
        );

        if (! $execution->wasRecentlyCreated) {
            return $this->handleExistingExecution($plannedAction, $execution);
        }

        return new AutomationExecutionResult(
            execution: $execution,
            status: AutomationExecutionStatus::Skipped,
        );
    }

    private function executeHandler(
        PlannedAutomationAction $plannedAction,
        ActionHandler $handler,
        AutomationExecution $execution,
    ): AutomationExecutionResult {
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

    /**
     * @return array<string, mixed>
     */
    private function executionAttributes(
        PlannedAutomationAction $plannedAction,
        string $idempotencyKey,
        AutomationExecutionStatus $status,
        ?string $errorMessage = null,
        ?string $externalId = null,
        array $metadata = [],
    ): array {
        $now = Carbon::now();

        return [
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
        ];
    }

    private function isUniqueConstraintViolation(QueryException|UniqueConstraintViolationException $exception): bool
    {
        if ($exception instanceof UniqueConstraintViolationException) {
            return true;
        }

        $sqlState = $exception->errorInfo[0] ?? null;

        return in_array($sqlState, ['23000', '23505', '2067'], true);
    }
}
