<?php

namespace Tests\Unit\Automation;

use App\Data\AutomationPolicyAction;
use App\Data\AutomationPolicyDueAction;
use App\Enums\AutomationPolicyActionType;
use App\Models\IncidentWaitingState;
use App\Services\Automation\ExecutionPlanner;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ExecutionPlannerTest extends TestCase
{
    public function test_plan_maps_due_actions_to_executable_runtime_actions(): void
    {
        $startedAt = Carbon::parse('2026-07-01 09:00:00');
        $waitingState = IncidentWaitingState::unguarded(fn (): IncidentWaitingState => new IncidentWaitingState([
            'id' => 42,
            'incident_id' => 10,
            'waiting_reason' => 'serial_number',
            'started_at' => $startedAt,
            'sla_paused' => true,
            'reminder_policy_key' => 'serial_number_default',
        ]));

        $dueActions = [
            new AutomationPolicyDueAction(
                day: 0,
                scheduledAt: $startedAt,
                action: new AutomationPolicyAction(
                    type: AutomationPolicyActionType::WhatsAppTemplate,
                    key: 'request_serial_number',
                ),
            ),
            new AutomationPolicyDueAction(
                day: 2,
                scheduledAt: $startedAt->copy()->addDays(2),
                action: new AutomationPolicyAction(
                    type: AutomationPolicyActionType::NotifyTeam,
                    key: 'serial_number_escalation',
                ),
            ),
        ];

        $plannedActions = app(ExecutionPlanner::class)->plan($waitingState, $dueActions);

        $this->assertCount(2, $plannedActions);

        $first = $plannedActions[0];
        $this->assertSame($waitingState, $first->waitingState);
        $this->assertSame('serial_number_default', $first->policyKey);
        $this->assertSame(0, $first->scheduleStep);
        $this->assertSame(AutomationPolicyActionType::WhatsAppTemplate, $first->actionType);
        $this->assertSame('request_serial_number', $first->actionKey);
        $this->assertNull($first->channel);
        $this->assertTrue($first->scheduledAt->equalTo($startedAt));

        $second = $plannedActions[1];
        $this->assertSame(2, $second->scheduleStep);
        $this->assertSame(AutomationPolicyActionType::NotifyTeam, $second->actionType);
        $this->assertSame('serial_number_escalation', $second->actionKey);
    }

    public function test_plan_returns_empty_list_for_no_due_actions(): void
    {
        $waitingState = IncidentWaitingState::unguarded(fn (): IncidentWaitingState => new IncidentWaitingState([
            'id' => 7,
            'incident_id' => 3,
            'waiting_reason' => 'serial_number',
            'started_at' => now(),
            'sla_paused' => true,
            'reminder_policy_key' => 'serial_number_default',
        ]));

        $plannedActions = app(ExecutionPlanner::class)->plan($waitingState, []);

        $this->assertSame([], $plannedActions);
    }
}
