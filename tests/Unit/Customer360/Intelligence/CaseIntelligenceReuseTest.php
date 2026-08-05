<?php

namespace Tests\Unit\Customer360\Intelligence;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\Customer360\Intelligence\Builders\CaseIntelligenceFactCollector;
use App\Services\Customer360\Intelligence\CaseIntelligenceEngine;
use App\Services\Customer360Service;
use App\Services\IncidentReferenceService;
use App\Support\Customer360\Customer360IraAdvisorPresenter;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class CaseIntelligenceReuseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        config(['ira.case_intelligence_engine.enabled' => true]);
    }

    public function test_snapshot_is_built_once_across_executive_advisor_and_workbench_surfaces(): void
    {
        [$incident] = $this->createIncident();
        $engine = app(CaseIntelligenceEngine::class);
        $service = app(Customer360Service::class);

        $first = $engine->build($incident);
        $this->assertNotNull($first);
        $this->assertSame(1, $engine->buildCountFor($incident));

        $service->executiveSummaryPayload($incident);
        $service->timelineTabPayload($incident);
        $aiPayload = $service->aiTabPayload($incident);

        $this->assertSame(1, $engine->buildCountFor($incident));
        $this->assertSame($first->workbench->scenario, $aiPayload['workbench']->scenario);
        $this->assertSame($first->workbench->incidentId, $aiPayload['workbench']->incidentId);
        $this->assertSame(
            $first->advisorViewModel,
            app(Customer360IraAdvisorPresenter::class)->presentFromSnapshot($first),
        );
        $this->assertSame(
            $first->executiveSummary->toArray(),
            $engine->build($incident)?->executiveSummary->toArray(),
        );
    }

    public function test_fact_collector_runs_once_when_multiple_payloads_use_engine(): void
    {
        [$incident] = $this->createIncident();

        $realCollector = app(CaseIntelligenceFactCollector::class);
        $collectCount = 0;

        $collector = Mockery::mock(CaseIntelligenceFactCollector::class);
        $collector->shouldReceive('collect')
            ->andReturnUsing(function (Incident $incident) use ($realCollector, &$collectCount) {
                $collectCount++;

                return $realCollector->collect($incident);
            });

        $this->app->instance(CaseIntelligenceFactCollector::class, $collector);
        $this->app->forgetInstance(CaseIntelligenceEngine::class);

        $service = app(Customer360Service::class);
        $service->executiveSummaryPayload($incident);
        $service->timelineTabPayload($incident);
        $service->aiTabPayload($incident);

        $this->assertSame(1, $collectCount);
    }

    public function test_advisor_presenter_from_snapshot_does_not_invoke_decision_builder(): void
    {
        [$incident] = $this->createIncident();
        config(['ira.case_intelligence_engine.enabled' => true]);

        $snapshot = app(CaseIntelligenceEngine::class)->build($incident);
        $this->assertNotNull($snapshot);

        $decisionBuilder = Mockery::mock(\App\Services\Customer360\Intelligence\Builders\CaseAdvisorDecisionBuilder::class);
        $decisionBuilder->shouldNotReceive('decide');

        $presenter = new Customer360IraAdvisorPresenter($decisionBuilder);
        $viewModel = $presenter->presentFromSnapshot($snapshot);

        $this->assertSame($snapshot->advisorViewModel, $viewModel);
    }

    public function test_refresh_workbench_forces_rebuild(): void
    {
        [$incident] = $this->createIncident();
        $engine = app(CaseIntelligenceEngine::class);
        $service = app(Customer360Service::class);

        $service->aiTabPayload($incident);
        $this->assertSame(1, $engine->buildCountFor($incident));

        $service->refreshAiWorkbench($incident);
        $this->assertSame(2, $engine->buildCountFor($incident));
    }

    public function test_snapshot_is_shared_across_engine_instances_via_incident_updated_at_cache(): void
    {
        [$incident] = $this->createIncident();

        $realCollector = app(CaseIntelligenceFactCollector::class);
        $collectCount = 0;

        $collector = Mockery::mock(CaseIntelligenceFactCollector::class);
        $collector->shouldReceive('collect')
            ->andReturnUsing(function (Incident $incident) use ($realCollector, &$collectCount) {
                $collectCount++;

                return $realCollector->collect($incident);
            });

        $this->app->instance(CaseIntelligenceFactCollector::class, $collector);

        $this->app->forgetInstance(CaseIntelligenceEngine::class);
        $first = app(CaseIntelligenceEngine::class)->build($incident);
        $this->assertNotNull($first);
        $this->assertSame(1, $collectCount);

        $this->app->forgetInstance(CaseIntelligenceEngine::class);
        $second = app(CaseIntelligenceEngine::class)->build($incident);
        $this->assertNotNull($second);
        $this->assertSame(1, $collectCount);
        $this->assertSame($first->incidentId, $second->incidentId);
        $this->assertSame($first->workbench->scenario, $second->workbench->scenario);
    }

    /**
     * @return array{0: Incident, 1: Order, 2: User}
     */
    private function createIncident(): array
    {
        $agent = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RD-CIE-REUSE',
            'serial_number' => null,
            'product_name' => 'FM220',
            'device_model' => 'FM220',
            'customer_name' => 'Reuse Customer',
            'customer_phone' => '9123456701',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Reuse case',
            'description' => 'Missing serial.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        $this->actingAs($agent);

        return [$incident->fresh(['order', 'assignee', 'activeWaitingState']), $order, $agent];
    }
}
