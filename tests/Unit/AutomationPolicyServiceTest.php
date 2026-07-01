<?php

namespace Tests\Unit;

use App\Data\AutomationPolicyDefinition;
use App\Enums\AutomationPolicyActionType;
use App\Exceptions\InvalidAutomationPolicyException;
use App\Exceptions\UnknownAutomationPolicyException;
use App\Models\IncidentWaitingState;
use App\Services\AutomationPolicyService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AutomationPolicyServiceTest extends TestCase
{
    private AutomationPolicyService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(AutomationPolicyService::class);
    }

    public function test_loads_serial_number_default_policy(): void
    {
        $policy = $this->service->load('serial_number_default');

        $this->assertInstanceOf(AutomationPolicyDefinition::class, $policy);
        $this->assertSame('serial_number_default', $policy->key);
        $this->assertSame('Serial Number Default', $policy->label);
        $this->assertCount(5, $policy->schedule);
        $this->assertSame([0, 2, 5, 7, 30], array_map(
            fn ($entry) => $entry->day,
            $policy->schedule,
        ));

        $dayZeroAction = $policy->schedule[0]->actions[0];
        $this->assertSame(AutomationPolicyActionType::WhatsAppTemplate, $dayZeroAction->type);
        $this->assertSame('request_serial_number', $dayZeroAction->key);

        $daySevenAction = $policy->schedule[3]->actions[0];
        $this->assertSame(AutomationPolicyActionType::NotifyTeam, $daySevenAction->type);
        $this->assertSame('serial_number_escalation', $daySevenAction->key);

        $dayThirtyAction = $policy->schedule[4]->actions[0];
        $this->assertSame(AutomationPolicyActionType::AutoClose, $dayThirtyAction->type);
        $this->assertSame('close_case_no_response', $dayThirtyAction->key);
    }

    public function test_unknown_policy_throws_exception(): void
    {
        $this->expectException(UnknownAutomationPolicyException::class);
        $this->expectExceptionMessage('Unknown automation policy [missing_policy].');

        $this->service->load('missing_policy');
    }

    public function test_invalid_policy_structure_is_detected(): void
    {
        config([
            'automation_policies.policies.invalid_policy' => [
                'label' => 'Broken Policy',
                'schedule' => [
                    [
                        'day' => 0,
                        'actions' => [
                            ['type' => 'unsupported_type', 'key' => 'broken'],
                        ],
                    ],
                ],
            ],
        ]);

        $this->expectException(InvalidAutomationPolicyException::class);
        $this->expectExceptionMessage('unsupported_type');

        $this->service->load('invalid_policy');
    }

    public function test_due_actions_include_all_elapsed_schedule_entries(): void
    {
        Carbon::setTestNow('2026-07-08 12:00:00');

        $startedAt = Carbon::parse('2026-07-01 09:00:00');
        $waitingState = $this->makeWaitingState($startedAt, 'serial_number_default');

        $dueActions = $this->service->dueActions($waitingState, Carbon::parse('2026-07-08 12:00:00'));

        $this->assertCount(4, $dueActions);
        $this->assertSame([0, 2, 5, 7], array_map(fn ($dueAction) => $dueAction->day, $dueActions));
        $this->assertSame(
            [
                'request_serial_number',
                'request_serial_number_reminder',
                'request_serial_number_reminder',
                'serial_number_escalation',
            ],
            array_map(fn ($dueAction) => $dueAction->action->key, $dueActions),
        );
        $this->assertTrue($dueActions[0]->scheduledAt->equalTo($startedAt));
        $this->assertTrue($dueActions[1]->scheduledAt->equalTo($startedAt->copy()->addDays(2)));
    }

    public function test_no_actions_before_scheduled_time(): void
    {
        Carbon::setTestNow('2026-07-02 08:59:59');

        $startedAt = Carbon::parse('2026-07-01 09:00:00');
        $waitingState = $this->makeWaitingState($startedAt, 'serial_number_default');

        $dueActions = $this->service->dueActions($waitingState, Carbon::parse('2026-07-02 08:59:59'));

        $this->assertCount(1, $dueActions);
        $this->assertSame(0, $dueActions[0]->day);
        $this->assertSame('request_serial_number', $dueActions[0]->action->key);
    }

    public function test_reference_before_started_at_returns_no_actions(): void
    {
        $startedAt = Carbon::parse('2026-07-01 09:00:00');
        $waitingState = $this->makeWaitingState($startedAt, 'serial_number_default');

        $dueActions = $this->service->dueActions($waitingState, Carbon::parse('2026-06-30 23:59:59'));

        $this->assertSame([], $dueActions);
    }

    public function test_waiting_state_without_policy_key_throws_unknown_policy_exception(): void
    {
        $waitingState = $this->makeWaitingState(Carbon::parse('2026-07-01 09:00:00'), null);

        $this->expectException(UnknownAutomationPolicyException::class);

        $this->service->dueActions($waitingState, now());
    }

    private function makeWaitingState(Carbon $startedAt, ?string $policyKey): IncidentWaitingState
    {
        return IncidentWaitingState::unguarded(fn (): IncidentWaitingState => new IncidentWaitingState([
            'incident_id' => 1,
            'waiting_reason' => 'serial_number',
            'started_at' => $startedAt,
            'sla_paused' => true,
            'reminder_policy_key' => $policyKey,
        ]));
    }
}
