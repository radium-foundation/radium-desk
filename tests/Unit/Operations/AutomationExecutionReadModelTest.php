<?php

namespace Tests\Unit\Operations;

use App\Enums\AutomationExecutionStatus;
use App\Enums\AutomationPolicyActionType;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\WaitingReason;
use App\Models\AutomationExecution;
use App\Models\Incident;
use App\Models\IncidentWaitingState;
use App\Models\Order;
use App\Models\User;
use App\ReadModels\Automation\AutomationExecutionReadModel;
use App\Services\IncidentReferenceService;
use App\Services\Operations\AutomationHealthService;
use App\Services\Operations\OperationsAutomationMetricsService;
use App\Services\Operations\OperationsRecentAutomationActivityService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AutomationExecutionReadModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
    }

    public function test_metrics_match_automation_health_overview(): void
    {
        Cache::flush();
        $this->seedExecutions();

        $healthOverview = app(AutomationHealthService::class)->overviewAggregation();
        $metrics = app(AutomationExecutionReadModel::class)->metrics();

        $this->assertSame((int) $healthOverview['executions_today'], $metrics->executionsToday);
        $this->assertSame((int) $healthOverview['success_today'], $metrics->successToday);
        $this->assertSame((int) $healthOverview['failures_today'], $metrics->failuresToday);
        $this->assertSame((int) $healthOverview['pending_executions'], $metrics->pendingExecutions);
        $this->assertSame($healthOverview['average_execution_ms'], $metrics->averageExecutionMs);

        $viaReadModel = app(AutomationExecutionReadModel::class)->healthOverview();
        foreach (['executions_today', 'success_today', 'failures_today', 'pending_executions', 'average_execution_ms'] as $field) {
            $this->assertSame($healthOverview[$field], $viaReadModel[$field], "Mismatch on {$field}");
        }
    }

    public function test_operations_performance_metrics_share_ledger_kpis(): void
    {
        Cache::flush();
        $this->seedExecutions();

        $shared = app(AutomationExecutionReadModel::class)->metrics();
        $ops = app(OperationsAutomationMetricsService::class)->metrics();

        $this->assertSame($shared->executionsToday, $ops['executions_today']);
        $this->assertSame($shared->failuresToday, $ops['failed']);
        $this->assertSame($shared->averageExecutionMs, $ops['average_execution_ms']);
        $this->assertSame(max(0, $shared->successToday - (int) $ops['partial_success']), $ops['success']);
    }

    public function test_activity_summary_matches_read_model(): void
    {
        Cache::flush();
        $this->seedExecutions();

        $fromReadModel = app(AutomationExecutionReadModel::class)->activitySummary()->toArray();
        $fromActivityService = app(OperationsRecentAutomationActivityService::class)->summary();

        $this->assertSame($fromReadModel, $fromActivityService);
        $this->assertSame(3, $fromActivityService['executions_today']);
        $this->assertSame(1, $fromActivityService['failures_today']);
    }

    public function test_consumers_reuse_h4_2_aggregation_cache(): void
    {
        Cache::flush();
        $this->seedExecutions();

        $cacheKey = AutomationHealthService::aggregationCacheKey();
        $this->assertFalse(Cache::has($cacheKey));

        app(AutomationHealthService::class)->dashboardData();
        $this->assertTrue(Cache::has($cacheKey));

        DB::enableQueryLog();
        DB::flushQueryLog();

        $ops = app(OperationsAutomationMetricsService::class)->metrics();
        $summary = app(OperationsRecentAutomationActivityService::class)->summary();
        $readModel = app(AutomationExecutionReadModel::class)->metrics();

        $queries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $query): bool => str_contains(strtolower($query), 'automation_executions')
                && str_contains(strtolower($query), 'count'))
            ->values();

        $this->assertSame($readModel->executionsToday, $ops['executions_today']);
        $this->assertSame($readModel->failuresToday, $summary['failures_today']);
        $this->assertCount(0, $queries, 'Shared KPIs must not re-run aggregation COUNT SQL on cache hit.');
    }

    public function test_same_response_before_and_after_read_model_projection(): void
    {
        Cache::flush();
        $this->seedExecutions();

        $service = app(AutomationHealthService::class);
        $before = $service->dashboardData()['overview'];

        Cache::flush();
        $after = app(AutomationExecutionReadModel::class)->healthOverview();
        $dashboardAgain = $service->dashboardData()['overview'];

        foreach (['executions_today', 'failures_today', 'pending_executions', 'average_execution_ms', 'success_today'] as $field) {
            $this->assertSame($before[$field], $after[$field], "Mismatch on {$field}");
            $this->assertSame($before[$field], $dashboardAgain[$field], "Dashboard mismatch on {$field}");
        }
    }

    private function seedExecutions(): void
    {
        $actor = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RD-READMODEL-001',
            'customer_name' => 'Read Model Customer',
            'serial_number' => 'FPSPL1141RM',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Read model test incident',
            'description' => 'AutomationExecutionReadModel parity seed.',
            'status' => IncidentStatus::Open,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        $waitingState = IncidentWaitingState::query()->create([
            'incident_id' => $incident->id,
            'waiting_reason' => WaitingReason::SerialNumber,
            'started_at' => now()->subHour(),
            'sla_paused' => true,
            'reminder_policy_key' => 'request_serial',
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        AutomationExecution::query()->create([
            'waiting_state_id' => $waitingState->id,
            'policy_key' => 'request_serial',
            'schedule_step' => 1,
            'action_type' => AutomationPolicyActionType::WhatsAppTemplate,
            'action_key' => 'request_serial_number',
            'channel' => 'whatsapp',
            'status' => AutomationExecutionStatus::Success,
            'idempotency_key' => 'automation.readmodel.success',
            'started_at' => now()->subMinutes(5),
            'completed_at' => now()->subMinutes(4),
        ]);

        AutomationExecution::query()->create([
            'waiting_state_id' => $waitingState->id,
            'policy_key' => 'request_serial',
            'schedule_step' => 2,
            'action_type' => AutomationPolicyActionType::WhatsAppTemplate,
            'action_key' => 'request_serial_number',
            'channel' => 'whatsapp',
            'status' => AutomationExecutionStatus::Failed,
            'idempotency_key' => 'automation.readmodel.failed',
            'error_message' => 'Channel rejected',
            'started_at' => now()->subMinutes(3),
            'completed_at' => now()->subMinutes(3),
        ]);

        AutomationExecution::query()->create([
            'waiting_state_id' => $waitingState->id,
            'policy_key' => 'request_serial',
            'schedule_step' => 3,
            'action_type' => AutomationPolicyActionType::WhatsAppTemplate,
            'action_key' => 'request_serial_number',
            'channel' => 'whatsapp',
            'status' => AutomationExecutionStatus::Pending,
            'idempotency_key' => 'automation.readmodel.pending',
            'started_at' => now()->subMinute(),
        ]);
    }
}
