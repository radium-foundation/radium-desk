<?php

namespace Tests\Unit\Automation;

use App\Data\Automation\AutomationExecutionResult;
use App\Data\Automation\AutomationRuntimeResult;
use App\Enums\AutomationExecutionStatus;
use App\Enums\AutomationPolicyActionType;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\WaitingReason;
use App\Models\AutomationExecution;
use App\Models\Incident;
use App\Models\IncidentWaitingState;
use App\Models\Order;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Automation\AutomationRuntime;
use App\Services\Automation\AutomationSchedulerService;
use App\Services\Automation\ExecutionPlanner;
use App\Services\Automation\WaitingStateScanner;
use App\Services\AutomationPolicyService;
use App\Services\IncidentReferenceService;
use App\Services\SystemSettingsService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

class AutomationSchedulerServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_run_exits_gracefully_when_scheduler_is_disabled(): void
    {
        $this->setSchedulerEnabled(false);

        $runtime = Mockery::mock(AutomationRuntime::class);
        $runtime->shouldNotReceive('execute');

        $result = $this->makeService($runtime)->run();

        $this->assertFalse($result->enabled);
    }

    public function test_run_scans_waiting_states_with_no_due_actions(): void
    {
        Carbon::setTestNow('2026-06-30 08:00:00');
        $this->setSchedulerEnabled(true);
        $this->createActiveWaitingState();

        $runtime = Mockery::mock(AutomationRuntime::class);
        $runtime->shouldNotReceive('execute');

        $result = $this->makeService($runtime)->run(Carbon::parse('2026-06-30 08:00:00'));

        $this->assertTrue($result->enabled);
        $this->assertSame(1, $result->waitingStatesScanned);
        $this->assertSame(0, $result->dueActionsFound);
        $this->assertSame(0, $result->executed);
    }

    public function test_run_executes_due_actions_via_runtime(): void
    {
        Carbon::setTestNow('2026-07-01 09:00:00');
        $this->setSchedulerEnabled(true);
        $waitingState = $this->createActiveWaitingState();

        $execution = AutomationExecution::unguarded(fn (): AutomationExecution => new AutomationExecution([
            'id' => 501,
            'waiting_state_id' => $waitingState->id,
            'policy_key' => 'serial_number_default',
            'schedule_step' => 0,
            'action_type' => AutomationPolicyActionType::WhatsAppTemplate,
            'action_key' => 'request_serial_number',
            'status' => AutomationExecutionStatus::Success,
            'idempotency_key' => 'automation.test',
        ]));

        $runtime = Mockery::mock(AutomationRuntime::class);
        $runtime->shouldReceive('execute')
            ->once()
            ->with(
                Mockery::on(fn ($state): bool => $state->id === $waitingState->id),
                Mockery::on(fn (array $plannedActions): bool => count($plannedActions) === 1
                    && $plannedActions[0]->actionKey === 'request_serial_number'),
            )
            ->andReturn(new AutomationRuntimeResult([
                new AutomationExecutionResult(
                    execution: $execution,
                    status: AutomationExecutionStatus::Success,
                ),
            ]));

        $result = $this->makeService($runtime)->run(Carbon::parse('2026-07-01 09:00:00'));

        $this->assertSame(1, $result->waitingStatesScanned);
        $this->assertSame(1, $result->dueActionsFound);
        $this->assertSame(1, $result->executed);
        $this->assertSame(0, $result->skipped);
        $this->assertSame(0, $result->failures);
    }

    public function test_run_counts_skipped_and_failed_runtime_results(): void
    {
        $this->setSchedulerEnabled(true);
        Carbon::setTestNow('2026-07-08 12:00:00');
        $waitingState = $this->createActiveWaitingState();

        $successExecution = AutomationExecution::unguarded(fn (): AutomationExecution => new AutomationExecution([
            'id' => 601,
            'waiting_state_id' => $waitingState->id,
            'policy_key' => 'serial_number_default',
            'schedule_step' => 0,
            'action_type' => AutomationPolicyActionType::WhatsAppTemplate,
            'action_key' => 'request_serial_number',
            'status' => AutomationExecutionStatus::Success,
            'idempotency_key' => 'automation.success',
        ]));

        $skippedExecution = AutomationExecution::unguarded(fn (): AutomationExecution => new AutomationExecution([
            'id' => 602,
            'waiting_state_id' => $waitingState->id,
            'policy_key' => 'serial_number_default',
            'schedule_step' => 2,
            'action_type' => AutomationPolicyActionType::WhatsAppTemplate,
            'action_key' => 'request_serial_number_reminder',
            'status' => AutomationExecutionStatus::Skipped,
            'idempotency_key' => 'automation.skipped',
        ]));

        $failedExecution = AutomationExecution::unguarded(fn (): AutomationExecution => new AutomationExecution([
            'id' => 603,
            'waiting_state_id' => $waitingState->id,
            'policy_key' => 'serial_number_default',
            'schedule_step' => 5,
            'action_type' => AutomationPolicyActionType::WhatsAppTemplate,
            'action_key' => 'request_serial_number_reminder',
            'status' => AutomationExecutionStatus::Failed,
            'idempotency_key' => 'automation.failed',
        ]));

        $runtime = Mockery::mock(AutomationRuntime::class);
        $runtime->shouldReceive('execute')
            ->once()
            ->andReturn(new AutomationRuntimeResult([
                new AutomationExecutionResult($successExecution, AutomationExecutionStatus::Success),
                new AutomationExecutionResult($skippedExecution, AutomationExecutionStatus::Skipped, skippedExisting: true),
                new AutomationExecutionResult($failedExecution, AutomationExecutionStatus::Failed),
            ]));

        $result = $this->makeService($runtime)->run(Carbon::parse('2026-07-08 12:00:00'));

        $this->assertSame(1, $result->executed);
        $this->assertSame(1, $result->skipped);
        $this->assertSame(1, $result->failures);
    }

    public function test_run_logs_scheduler_summary(): void
    {
        $this->setSchedulerEnabled(true);
        Carbon::setTestNow('2026-07-01 09:00:00');
        $this->createActiveWaitingState();

        $execution = AutomationExecution::unguarded(fn (): AutomationExecution => new AutomationExecution([
            'id' => 701,
            'waiting_state_id' => 1,
            'policy_key' => 'serial_number_default',
            'schedule_step' => 0,
            'action_type' => AutomationPolicyActionType::WhatsAppTemplate,
            'action_key' => 'request_serial_number',
            'status' => AutomationExecutionStatus::Success,
            'idempotency_key' => 'automation.log',
        ]));

        $runtime = Mockery::mock(AutomationRuntime::class);
        $runtime->shouldReceive('execute')->once()->andReturn(new AutomationRuntimeResult([
            new AutomationExecutionResult($execution, AutomationExecutionStatus::Success),
        ]));

        $result = $this->makeService($runtime)->run(Carbon::parse('2026-07-01 09:00:00'));

        $this->assertSame(1, $result->waitingStatesScanned);
        $this->assertSame(1, $result->dueActionsFound);
        $this->assertSame(1, $result->executed);
        $this->assertSame(0, $result->skipped);
        $this->assertSame(0, $result->failures);
    }

    private function makeService(AutomationRuntime $runtime): AutomationSchedulerService
    {
        return new AutomationSchedulerService(
            app(SystemSettingsService::class),
            app(WaitingStateScanner::class),
            app(AutomationPolicyService::class),
            app(ExecutionPlanner::class),
            $runtime,
        );
    }

    private function setSchedulerEnabled(bool $enabled): void
    {
        SystemSetting::query()->updateOrCreate(
            ['key' => 'automation.scheduler.enabled'],
            ['value' => $enabled ? '1' : '0'],
        );

        app(SystemSettingsService::class)->forget('automation.scheduler.enabled');
    }

    private function createActiveWaitingState(): IncidentWaitingState
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-SCHED-'.uniqid(),
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
            'title' => 'Scheduler case',
            'description' => 'Scheduler case.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        return IncidentWaitingState::query()->create([
            'incident_id' => $incident->id,
            'waiting_reason' => WaitingReason::SerialNumber,
            'started_at' => Carbon::parse('2026-07-01 09:00:00'),
            'sla_paused' => true,
            'reminder_policy_key' => 'serial_number_default',
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);
    }
}
