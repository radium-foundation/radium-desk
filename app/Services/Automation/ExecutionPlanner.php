<?php

namespace App\Services\Automation;

use App\Data\Automation\PlannedAutomationAction;
use App\Data\AutomationPolicyDueAction;
use App\Models\IncidentWaitingState;

class ExecutionPlanner
{
    /**
     * @param  list<AutomationPolicyDueAction>  $dueActions
     * @return list<PlannedAutomationAction>
     */
    public function plan(IncidentWaitingState $waitingState, array $dueActions): array
    {
        $policyKey = (string) $waitingState->reminder_policy_key;

        $plannedActions = [];

        foreach ($dueActions as $dueAction) {
            $plannedActions[] = new PlannedAutomationAction(
                waitingState: $waitingState,
                policyKey: $policyKey,
                scheduleStep: $dueAction->day,
                actionType: $dueAction->action->type,
                actionKey: $dueAction->action->key,
                channel: null,
                scheduledAt: $dueAction->scheduledAt,
                actionConfig: $dueAction->action->config,
            );
        }

        return $plannedActions;
    }
}
