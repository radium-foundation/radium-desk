<?php

namespace Tests\Unit\Automation;

use App\Data\Automation\PlannedAutomationAction;
use App\Data\NotificationDispatchResult;
use App\Data\NotificationMessage;
use App\Data\NotificationResult;
use App\Enums\AutomationExecutionStatus;
use App\Enums\AutomationPolicyActionType;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\NotificationChannelType;
use App\Enums\NotificationType;
use App\Enums\WaitingReason;
use App\Models\AutomationExecution;
use App\Models\Incident;
use App\Models\IncidentWaitingState;
use App\Models\Order;
use App\Models\User;
use App\Services\Automation\AutomationIdempotencyKeyGenerator;
use App\Services\Automation\CustomerWaitingLifecycleService;
use App\Services\Automation\AutomationNotificationTypeResolver;
use App\Services\Automation\AutomationRuntime;
use App\Services\Automation\ExecutionPlanner;
use App\Services\Automation\Handlers\NotificationActionHandler;
use App\Services\Automation\Handlers\NotifyTeamActionHandler;
use App\Services\AutomationPolicyService;
use App\Services\IncidentReferenceService;
use App\Services\IncidentWaitingStateService;
use App\Services\Notifications\NotificationDispatcher;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

class AutomationRuntimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_runtime_executes_planned_actions_and_persists_records(): void
    {
        [$waitingState, $plannedActions] = $this->makePlannedActions();

        $notificationDispatcher = Mockery::mock(NotificationDispatcher::class);
        $notificationDispatcher->shouldReceive('send')
            ->once()
            ->with(
                NotificationType::RequestSerialNumber,
                Mockery::type(NotificationMessage::class),
            )
            ->andReturn(NotificationDispatchResult::fromResults([
                NotificationResult::success(
                    channel: NotificationChannelType::WhatsApp,
                    externalId: 'msg-runtime-001',
                    message: 'WhatsApp template sent successfully.',
                ),
            ]));

        $runtime = new AutomationRuntime(
            app(AutomationIdempotencyKeyGenerator::class),
            [new NotificationActionHandler($notificationDispatcher, app(AutomationNotificationTypeResolver::class), app(\App\Services\Notifications\NotificationDeliverySummaryFormatter::class), app(CustomerWaitingLifecycleService::class), app(\App\Services\Notifications\CustomerAutomationEligibilityService::class))],
        );

        $result = $runtime->execute($waitingState, $plannedActions);

        $this->assertCount(1, $result->results);
        $this->assertSame(1, $result->executedCount());
        $this->assertSame(0, $result->skippedCount());
        $this->assertSame(AutomationExecutionStatus::Success, $result->results[0]->status);

        $this->assertDatabaseHas('automation_executions', [
            'waiting_state_id' => $waitingState->id,
            'policy_key' => 'serial_number_default',
            'schedule_step' => 0,
            'action_type' => AutomationPolicyActionType::WhatsAppTemplate->value,
            'action_key' => 'request_serial_number',
            'status' => AutomationExecutionStatus::Success->value,
            'external_id' => 'msg-runtime-001',
        ]);

        $execution = AutomationExecution::query()->first();
        $this->assertNotNull($execution);
        $this->assertNotNull($execution->started_at);
        $this->assertNotNull($execution->completed_at);
        $this->assertSame(
            app(AutomationIdempotencyKeyGenerator::class)->generate(
                waitingStateId: $waitingState->id,
                policyKey: 'serial_number_default',
                scheduleStep: 0,
                actionType: AutomationPolicyActionType::WhatsAppTemplate,
                channel: null,
            ),
            $execution->idempotency_key,
        );
    }

    public function test_runtime_skips_actions_that_already_succeeded(): void
    {
        [$waitingState, $plannedActions] = $this->makePlannedActions();

        $notificationDispatcher = Mockery::mock(NotificationDispatcher::class);
        $notificationDispatcher->shouldReceive('send')->once()->andReturn(
            NotificationDispatchResult::fromResults([
                NotificationResult::success(
                    channel: NotificationChannelType::WhatsApp,
                    externalId: 'msg-runtime-001',
                ),
            ]),
        );

        $runtime = new AutomationRuntime(
            app(AutomationIdempotencyKeyGenerator::class),
            [new NotificationActionHandler($notificationDispatcher, app(AutomationNotificationTypeResolver::class), app(\App\Services\Notifications\NotificationDeliverySummaryFormatter::class), app(CustomerWaitingLifecycleService::class), app(\App\Services\Notifications\CustomerAutomationEligibilityService::class))],
        );

        $firstRun = $runtime->execute($waitingState, $plannedActions);
        $secondRun = $runtime->execute($waitingState, $plannedActions);

        $this->assertSame(AutomationExecutionStatus::Success, $firstRun->results[0]->status);
        $this->assertTrue($secondRun->results[0]->wasSkipped());
        $this->assertSame(1, AutomationExecution::query()->count());
    }

    public function test_runtime_marks_unhandled_action_types_as_skipped(): void
    {
        [$waitingState] = $this->makePlannedActions();

        $plannedActions = app(ExecutionPlanner::class)->plan(
            $waitingState,
            app(AutomationPolicyService::class)->dueActions(
                $waitingState,
                Carbon::parse('2026-07-08 12:00:00'),
            ),
        );

        $escalationAction = collect($plannedActions)
            ->first(fn ($action) => $action->actionKey === 'serial_number_escalation');

        $this->assertNotNull($escalationAction);

        $runtime = new AutomationRuntime(
            app(AutomationIdempotencyKeyGenerator::class),
            [app(NotificationActionHandler::class)],
        );

        $result = $runtime->execute($waitingState, [$escalationAction]);

        $this->assertTrue($result->results[0]->wasSkipped());
        $this->assertDatabaseHas('automation_executions', [
            'waiting_state_id' => $waitingState->id,
            'action_key' => 'serial_number_escalation',
            'status' => AutomationExecutionStatus::Skipped->value,
            'error_message' => AutomationRuntime::MISSING_HANDLER_ERROR_MESSAGE,
        ]);
    }

    public function test_runtime_persists_failed_executions(): void
    {
        [$waitingState, $plannedActions] = $this->makePlannedActions();

        $notificationDispatcher = Mockery::mock(NotificationDispatcher::class);
        $notificationDispatcher->shouldReceive('send')
            ->once()
            ->andReturn(NotificationDispatchResult::fromResults([
                NotificationResult::failure(
                    channel: NotificationChannelType::WhatsApp,
                    message: 'WhatsApp dispatch failed.',
                    retryable: true,
                ),
            ]));

        $runtime = new AutomationRuntime(
            app(AutomationIdempotencyKeyGenerator::class),
            [new NotificationActionHandler($notificationDispatcher, app(AutomationNotificationTypeResolver::class), app(\App\Services\Notifications\NotificationDeliverySummaryFormatter::class), app(CustomerWaitingLifecycleService::class), app(\App\Services\Notifications\CustomerAutomationEligibilityService::class))],
        );

        $result = $runtime->execute($waitingState, $plannedActions);

        $this->assertSame(AutomationExecutionStatus::Failed, $result->results[0]->status);
        $this->assertDatabaseHas('automation_executions', [
            'waiting_state_id' => $waitingState->id,
            'status' => AutomationExecutionStatus::Failed->value,
            'error_message' => "Notification failed\n✗ WhatsApp: WhatsApp dispatch failed.",
        ]);
    }

    public function test_runtime_retries_after_previous_failure_without_duplicate_insert(): void
    {
        [$waitingState, $plannedActions] = $this->makePlannedActions();
        $idempotencyKey = app(AutomationIdempotencyKeyGenerator::class)->generate(
            waitingStateId: $waitingState->id,
            policyKey: 'serial_number_default',
            scheduleStep: 0,
            actionType: AutomationPolicyActionType::WhatsAppTemplate,
            channel: null,
        );

        AutomationExecution::query()->create([
            'waiting_state_id' => $waitingState->id,
            'policy_key' => 'serial_number_default',
            'schedule_step' => 0,
            'action_type' => AutomationPolicyActionType::WhatsAppTemplate,
            'action_key' => 'request_serial_number',
            'status' => AutomationExecutionStatus::Failed,
            'idempotency_key' => $idempotencyKey,
            'error_message' => 'WhatsApp dispatch failed.',
            'started_at' => Carbon::parse('2026-07-01 08:00:00'),
            'completed_at' => Carbon::parse('2026-07-01 08:00:00'),
        ]);

        $notificationDispatcher = Mockery::mock(NotificationDispatcher::class);
        $notificationDispatcher->shouldReceive('send')
            ->once()
            ->andReturn(NotificationDispatchResult::fromResults([
                NotificationResult::success(
                    channel: NotificationChannelType::WhatsApp,
                    externalId: 'msg-runtime-retry-001',
                ),
            ]));

        $runtime = new AutomationRuntime(
            app(AutomationIdempotencyKeyGenerator::class),
            [new NotificationActionHandler($notificationDispatcher, app(AutomationNotificationTypeResolver::class), app(\App\Services\Notifications\NotificationDeliverySummaryFormatter::class), app(CustomerWaitingLifecycleService::class), app(\App\Services\Notifications\CustomerAutomationEligibilityService::class))],
        );

        $result = $runtime->execute($waitingState, $plannedActions);

        $this->assertSame(AutomationExecutionStatus::Success, $result->results[0]->status);
        $this->assertSame(1, AutomationExecution::query()->count());
        $this->assertDatabaseHas('automation_executions', [
            'idempotency_key' => $idempotencyKey,
            'status' => AutomationExecutionStatus::Success->value,
            'external_id' => 'msg-runtime-retry-001',
            'error_message' => null,
        ]);
    }

    public function test_runtime_recovers_stale_pending_execution(): void
    {
        [$waitingState, $plannedActions] = $this->makePlannedActions();
        $idempotencyKey = app(AutomationIdempotencyKeyGenerator::class)->generate(
            waitingStateId: $waitingState->id,
            policyKey: 'serial_number_default',
            scheduleStep: 0,
            actionType: AutomationPolicyActionType::WhatsAppTemplate,
            channel: null,
        );

        AutomationExecution::query()->create([
            'waiting_state_id' => $waitingState->id,
            'policy_key' => 'serial_number_default',
            'schedule_step' => 0,
            'action_type' => AutomationPolicyActionType::WhatsAppTemplate,
            'action_key' => 'request_serial_number',
            'status' => AutomationExecutionStatus::Pending,
            'idempotency_key' => $idempotencyKey,
            'started_at' => Carbon::parse('2026-07-01 07:00:00'),
            'completed_at' => null,
        ]);

        $notificationDispatcher = Mockery::mock(NotificationDispatcher::class);
        $notificationDispatcher->shouldReceive('send')
            ->once()
            ->andReturn(NotificationDispatchResult::fromResults([
                NotificationResult::success(
                    channel: NotificationChannelType::WhatsApp,
                    externalId: 'msg-runtime-stale-001',
                ),
            ]));

        $runtime = new AutomationRuntime(
            app(AutomationIdempotencyKeyGenerator::class),
            [new NotificationActionHandler($notificationDispatcher, app(AutomationNotificationTypeResolver::class), app(\App\Services\Notifications\NotificationDeliverySummaryFormatter::class), app(CustomerWaitingLifecycleService::class), app(\App\Services\Notifications\CustomerAutomationEligibilityService::class))],
        );

        $result = $runtime->execute($waitingState, $plannedActions);

        $this->assertSame(AutomationExecutionStatus::Success, $result->results[0]->status);
        $this->assertSame(1, AutomationExecution::query()->count());
        $this->assertDatabaseHas('automation_executions', [
            'idempotency_key' => $idempotencyKey,
            'status' => AutomationExecutionStatus::Success->value,
            'external_id' => 'msg-runtime-stale-001',
        ]);
    }

    public function test_runtime_skips_recent_pending_execution_owned_by_another_worker(): void
    {
        [$waitingState, $plannedActions] = $this->makePlannedActions();
        $idempotencyKey = app(AutomationIdempotencyKeyGenerator::class)->generate(
            waitingStateId: $waitingState->id,
            policyKey: 'serial_number_default',
            scheduleStep: 0,
            actionType: AutomationPolicyActionType::WhatsAppTemplate,
            channel: null,
        );

        AutomationExecution::query()->create([
            'waiting_state_id' => $waitingState->id,
            'policy_key' => 'serial_number_default',
            'schedule_step' => 0,
            'action_type' => AutomationPolicyActionType::WhatsAppTemplate,
            'action_key' => 'request_serial_number',
            'status' => AutomationExecutionStatus::Pending,
            'idempotency_key' => $idempotencyKey,
            'started_at' => Carbon::parse('2026-07-01 08:30:00'),
            'completed_at' => null,
        ]);

        $notificationDispatcher = Mockery::mock(NotificationDispatcher::class);
        $notificationDispatcher->shouldReceive('send')->never();

        $runtime = new AutomationRuntime(
            app(AutomationIdempotencyKeyGenerator::class),
            [new NotificationActionHandler($notificationDispatcher, app(AutomationNotificationTypeResolver::class), app(\App\Services\Notifications\NotificationDeliverySummaryFormatter::class), app(CustomerWaitingLifecycleService::class), app(\App\Services\Notifications\CustomerAutomationEligibilityService::class))],
        );

        $result = $runtime->execute($waitingState, $plannedActions);

        $this->assertTrue($result->results[0]->wasSkipped());
        $this->assertTrue($result->results[0]->skippedExisting);
        $this->assertSame(1, AutomationExecution::query()->count());
        $this->assertDatabaseHas('automation_executions', [
            'idempotency_key' => $idempotencyKey,
            'status' => AutomationExecutionStatus::Pending->value,
        ]);
    }

    public function test_runtime_handles_duplicate_key_race_without_escaping_sql_exception(): void
    {
        [$waitingState, $plannedActions] = $this->makePlannedActions();
        $idempotencyKey = app(AutomationIdempotencyKeyGenerator::class)->generate(
            waitingStateId: $waitingState->id,
            policyKey: 'serial_number_default',
            scheduleStep: 0,
            actionType: AutomationPolicyActionType::WhatsAppTemplate,
            channel: null,
        );

        $notificationDispatcher = Mockery::mock(NotificationDispatcher::class);
        $notificationDispatcher->shouldReceive('send')->never();

        AutomationExecution::creating(function (AutomationExecution $model) use ($waitingState, $idempotencyKey): void {
            static $seeded = false;

            if ($seeded || $model->idempotency_key !== $idempotencyKey) {
                return;
            }

            $seeded = true;

            AutomationExecution::withoutEvents(function () use ($waitingState, $idempotencyKey): void {
                AutomationExecution::query()->create([
                    'waiting_state_id' => $waitingState->id,
                    'policy_key' => 'serial_number_default',
                    'schedule_step' => 0,
                    'action_type' => AutomationPolicyActionType::WhatsAppTemplate,
                    'action_key' => 'request_serial_number',
                    'status' => AutomationExecutionStatus::Success,
                    'idempotency_key' => $idempotencyKey,
                    'external_id' => 'msg-runtime-race-001',
                    'started_at' => Carbon::parse('2026-07-01 08:00:00'),
                    'completed_at' => Carbon::parse('2026-07-01 08:00:00'),
                ]);
            });
        });

        $runtime = new AutomationRuntime(
            app(AutomationIdempotencyKeyGenerator::class),
            [new NotificationActionHandler($notificationDispatcher, app(AutomationNotificationTypeResolver::class), app(\App\Services\Notifications\NotificationDeliverySummaryFormatter::class), app(CustomerWaitingLifecycleService::class), app(\App\Services\Notifications\CustomerAutomationEligibilityService::class))],
        );

        $result = $runtime->execute($waitingState, $plannedActions);

        $this->assertTrue($result->results[0]->wasSkipped());
        $this->assertTrue($result->results[0]->skippedExisting);
        $this->assertSame(1, AutomationExecution::query()->count());
    }

    public function test_runtime_does_not_duplicate_terminal_skipped_execution(): void
    {
        [$waitingState] = $this->makePlannedActions();

        $plannedActions = app(ExecutionPlanner::class)->plan(
            $waitingState,
            app(AutomationPolicyService::class)->dueActions(
                $waitingState,
                Carbon::parse('2026-07-08 12:00:00'),
            ),
        );

        $escalationAction = collect($plannedActions)
            ->first(fn ($action) => $action->actionKey === 'serial_number_escalation');

        $this->assertNotNull($escalationAction);

        $runtime = new AutomationRuntime(
            app(AutomationIdempotencyKeyGenerator::class),
            [app(NotificationActionHandler::class)],
        );

        $firstRun = $runtime->execute($waitingState, [$escalationAction]);
        $secondRun = $runtime->execute($waitingState, [$escalationAction]);

        $this->assertTrue($firstRun->results[0]->wasSkipped());
        $this->assertTrue($secondRun->results[0]->wasSkipped());
        $this->assertTrue($secondRun->results[0]->skippedExisting);
        $this->assertSame(1, AutomationExecution::query()->count());
    }

    public function test_container_runtime_resolves_notify_team_handler_for_day_seven_escalation(): void
    {
        [$waitingState] = $this->makePlannedActions();

        $plannedActions = app(ExecutionPlanner::class)->plan(
            $waitingState,
            app(AutomationPolicyService::class)->dueActions(
                $waitingState,
                Carbon::parse('2026-07-08 12:00:00'),
            ),
        );

        $escalationAction = collect($plannedActions)
            ->first(fn ($action) => $action->scheduleStep === 7
                && $action->actionKey === 'serial_number_escalation');

        $this->assertNotNull($escalationAction);
        $this->assertSame('serial_number_default', $escalationAction->policyKey);
        $this->assertSame(AutomationPolicyActionType::NotifyTeam, $escalationAction->actionType);

        $runtime = app(AutomationRuntime::class);
        $result = $runtime->execute($waitingState, [$escalationAction]);

        $this->assertSame(AutomationExecutionStatus::Success, $result->results[0]->status);
        $this->assertDatabaseHas('automation_executions', [
            'waiting_state_id' => $waitingState->id,
            'policy_key' => 'serial_number_default',
            'schedule_step' => 7,
            'action_type' => AutomationPolicyActionType::NotifyTeam->value,
            'action_key' => 'serial_number_escalation',
            'status' => AutomationExecutionStatus::Success->value,
        ]);
    }

    public function test_runtime_retries_skipped_execution_when_handler_was_missing_but_is_now_registered(): void
    {
        [$waitingState] = $this->makePlannedActions();

        $plannedActions = app(ExecutionPlanner::class)->plan(
            $waitingState,
            app(AutomationPolicyService::class)->dueActions(
                $waitingState,
                Carbon::parse('2026-07-08 12:00:00'),
            ),
        );

        $escalationAction = collect($plannedActions)
            ->first(fn ($action) => $action->actionKey === 'serial_number_escalation');

        $this->assertNotNull($escalationAction);

        $idempotencyKey = app(AutomationIdempotencyKeyGenerator::class)->generate(
            waitingStateId: $waitingState->id,
            policyKey: 'serial_number_default',
            scheduleStep: 7,
            actionType: AutomationPolicyActionType::NotifyTeam,
            channel: null,
        );

        AutomationExecution::query()->create([
            'waiting_state_id' => $waitingState->id,
            'policy_key' => 'serial_number_default',
            'schedule_step' => 7,
            'action_type' => AutomationPolicyActionType::NotifyTeam,
            'action_key' => 'serial_number_escalation',
            'status' => AutomationExecutionStatus::Skipped,
            'idempotency_key' => $idempotencyKey,
            'error_message' => AutomationRuntime::MISSING_HANDLER_ERROR_MESSAGE,
            'started_at' => Carbon::parse('2026-07-01 08:00:00'),
            'completed_at' => Carbon::parse('2026-07-01 08:00:00'),
        ]);

        $runtime = new AutomationRuntime(
            app(AutomationIdempotencyKeyGenerator::class),
            [
                app(NotificationActionHandler::class),
                app(NotifyTeamActionHandler::class),
            ],
        );

        $result = $runtime->execute($waitingState, [$escalationAction]);

        $this->assertSame(AutomationExecutionStatus::Success, $result->results[0]->status);
        $this->assertSame(1, AutomationExecution::query()->count());
        $this->assertDatabaseHas('automation_executions', [
            'idempotency_key' => $idempotencyKey,
            'status' => AutomationExecutionStatus::Success->value,
            'error_message' => null,
        ]);
    }

    public function test_idempotency_key_is_deterministic_for_same_action(): void
    {
        $generator = app(AutomationIdempotencyKeyGenerator::class);

        $first = $generator->generate(10, 'serial_number_default', 2, AutomationPolicyActionType::WhatsAppTemplate, null);
        $second = $generator->generate(10, 'serial_number_default', 2, AutomationPolicyActionType::WhatsAppTemplate, null);
        $different = $generator->generate(10, 'serial_number_default', 5, AutomationPolicyActionType::WhatsAppTemplate, null);

        $this->assertSame($first, $second);
        $this->assertNotSame($first, $different);
        $this->assertSame('automation.10.serial_number_default.2.whatsapp_template.none', $first);
    }

    /**
     * @return array{0: IncidentWaitingState, 1: list<PlannedAutomationAction>}
     */
    private function makePlannedActions(): array
    {
        Carbon::setTestNow('2026-07-01 09:00:00');

        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-AUTO-RT-'.uniqid(),
            'serial_number' => null,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Automation runtime case',
            'description' => 'Automation runtime case.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        app(IncidentWaitingStateService::class)->start(
            incident: $incident,
            reason: WaitingReason::SerialNumber,
            actor: $agent,
            reminderPolicyKey: 'serial_number_default',
        );

        $waitingState = IncidentWaitingState::query()->firstOrFail();
        $dueActions = app(AutomationPolicyService::class)->dueActions($waitingState, Carbon::parse('2026-07-01 09:00:00'));
        $plannedActions = app(ExecutionPlanner::class)->plan($waitingState, [$dueActions[0]]);

        return [$waitingState, $plannedActions];
    }
}
