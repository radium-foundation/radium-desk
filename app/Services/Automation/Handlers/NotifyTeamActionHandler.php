<?php

namespace App\Services\Automation\Handlers;

use App\Contracts\Automation\ActionHandler;
use App\Data\Automation\ActionHandlerResult;
use App\Data\Automation\PlannedAutomationAction;
use App\Enums\AutomationPolicyActionType;
use App\Models\User;
use App\Services\AuditLogService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;

class NotifyTeamActionHandler implements ActionHandler
{
    public const AUDIT_EVENT_SERIAL_NUMBER_ESCALATION = 'automation.serial_number_escalation';

    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    public function supports(AutomationPolicyActionType $type): bool
    {
        return $type === AutomationPolicyActionType::NotifyTeam;
    }

    public function handle(PlannedAutomationAction $action): ActionHandlerResult
    {
        return match ($action->actionKey) {
            'serial_number_escalation' => $this->escalateSerialNumberWaitingState($action),
            default => ActionHandlerResult::failure("No notify-team handler exists for action key [{$action->actionKey}]."),
        };
    }

    private function escalateSerialNumberWaitingState(PlannedAutomationAction $action): ActionHandlerResult
    {
        $waitingState = $action->waitingState;
        $waitingState->loadMissing(['incident.order']);

        $incident = $waitingState->incident;
        $order = $incident?->order;

        if ($incident === null || $order === null) {
            return ActionHandlerResult::failure('Incident order context is required for notify-team actions.');
        }

        $coordinator = $this->resolveCoordinatorAssignee($order);

        DB::transaction(function () use ($incident, $order, $coordinator, $action): void {
            $updates = ['high_priority' => true];

            if ($coordinator !== null) {
                $updates['assigned_to_user_id'] = $coordinator->id;
            }

            $incident->update($updates);

            $this->auditLogService->log(
                userId: $coordinator?->id,
                event: self::AUDIT_EVENT_SERIAL_NUMBER_ESCALATION,
                auditable: $incident->fresh(),
                newValues: [
                    'policy_key' => $action->policyKey,
                    'schedule_step' => $action->scheduleStep,
                    'action_key' => $action->actionKey,
                    'coordinator_user_id' => $coordinator?->id,
                    'high_priority' => true,
                ],
            );
        });

        return ActionHandlerResult::success(metadata: [
            'coordinator_user_id' => $coordinator?->id,
            'high_priority' => true,
        ]);
    }

    private function resolveCoordinatorAssignee(\App\Models\Order $order): ?User
    {
        $coordinators = User::query()
            ->where('is_active', true)
            ->role(RolePermissionSeeder::ROLE_CUSTOMER_COORDINATOR)
            ->orderBy('id')
            ->get();

        if ($coordinators->isEmpty()) {
            return null;
        }

        $index = $order->id % $coordinators->count();

        return $coordinators->get($index);
    }
}
