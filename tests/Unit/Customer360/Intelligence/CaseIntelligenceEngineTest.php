<?php

namespace Tests\Unit\Customer360\Intelligence;

use App\Data\AI\IRAExecutiveSummaryDTO;
use App\Data\Customer360\Intelligence\CaseIntelligenceSnapshot;
use App\Data\Customer360\Intelligence\CaseReasoningResult;
use App\Data\Customer360\Intelligence\CaseStory;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\Customer360\Intelligence\CaseIntelligenceEngine;
use App\Services\Customer360Service;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CaseIntelligenceEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_feature_flag_defaults_to_enabled(): void
    {
        $engine = app(CaseIntelligenceEngine::class);

        $this->assertTrue($engine->enabled());
        $this->assertTrue((bool) config('ira.case_intelligence_engine.enabled'));
    }

    public function test_feature_flag_can_be_disabled_for_rollback(): void
    {
        config(['ira.case_intelligence_engine.enabled' => false]);

        $this->assertFalse(app(CaseIntelligenceEngine::class)->enabled());
    }

    public function test_build_returns_null_when_facts_collector_finds_no_order(): void
    {
        [$incident] = $this->createSerialPendingIncident();

        $collector = \Mockery::mock(\App\Services\Customer360\Intelligence\Builders\CaseIntelligenceFactCollector::class);
        $collector->shouldReceive('collect')->with(\Mockery::type(Incident::class))->andReturn(null);

        $this->app->instance(
            \App\Services\Customer360\Intelligence\Builders\CaseIntelligenceFactCollector::class,
            $collector,
        );

        $engine = app(CaseIntelligenceEngine::class);

        $this->assertNull($engine->build($incident));
        $this->assertNull($engine->executiveSummary($incident));
    }

    public function test_build_produces_canonical_snapshot_with_executive_summary(): void
    {
        [$incident] = $this->createSerialPendingIncident();

        $snapshot = app(CaseIntelligenceEngine::class)->build($incident);

        $this->assertInstanceOf(CaseIntelligenceSnapshot::class, $snapshot);
        $this->assertSame($incident->id, $snapshot->incidentId);
        $this->assertSame(CaseIntelligenceSnapshot::SCHEMA_VERSION, $snapshot->schemaVersion);
        $this->assertInstanceOf(IRAExecutiveSummaryDTO::class, $snapshot->executiveSummary);
        $this->assertNotEmpty($snapshot->executiveSummary->executiveSummary);
        $this->assertNotSame('', $snapshot->executiveSummary->recommendation);
        $this->assertTrue($snapshot->serialMissing);
        $this->assertContains($snapshot->waitingParty, ['customer', 'none']);
        $this->assertNotSame('', $snapshot->currentStatusCode);
        $this->assertNotSame('', $snapshot->currentStatusLabel);
        $this->assertSame('unknown', $snapshot->customerMoodLevel);
        $this->assertNotEmpty($snapshot->recommendedAction->actionKey);
        $this->assertIsArray($snapshot->blockers);
        $this->assertIsArray($snapshot->risks);
        $this->assertIsArray($snapshot->evidence);
        $this->assertNotNull($snapshot->workbench);
        $this->assertIsArray($snapshot->evidenceForView());
        $this->assertInstanceOf(CaseReasoningResult::class, $snapshot->reasoning);
        $this->assertInstanceOf(CaseStory::class, $snapshot->caseStory);
        $this->assertArrayHasKey('current_situation', $snapshot->caseStory->toArray());
        $this->assertNotNull($snapshot->toLanguageEnhancerPayload()['case_story']);
    }

    public function test_executive_summary_recommendation_matches_canonical_recommended_action(): void
    {
        [$incident] = $this->createSerialPendingIncident();

        $snapshot = app(CaseIntelligenceEngine::class)->build($incident);
        $this->assertNotNull($snapshot);
        $this->assertInstanceOf(IRAExecutiveSummaryDTO::class, $snapshot->executiveSummary);

        // Q2: one canonical recommendation across executive summary + recommended action + case story.
        $canonical = (string) ($snapshot->recommendedAction->recommendationText
            ?? $snapshot->recommendedAction->label);
        $this->assertSame($canonical, $snapshot->executiveSummary->recommendation);
        $this->assertContains($canonical, $snapshot->caseStory?->recommendedAction ?? []);
        $this->assertSame(
            $snapshot->recommendedAction->label,
            $snapshot->caseStory?->recommendedAction[0] ?? null,
        );
    }

    public function test_executive_summary_opinion_still_surfaces_in_legacy_payload_when_flag_off(): void
    {
        [$incident] = $this->createSerialPendingIncident();

        config(['ira.case_intelligence_engine.enabled' => true]);
        $engineSummary = app(CaseIntelligenceEngine::class)->executiveSummary($incident);
        $this->assertInstanceOf(IRAExecutiveSummaryDTO::class, $engineSummary);

        config(['ira.case_intelligence_engine.enabled' => false]);
        $legacySummary = app(Customer360Service::class)
            ->executiveSummaryPayload($incident);

        $this->assertStringContainsString(
            $engineSummary->opinion,
            $legacySummary['html'],
        );
    }

    public function test_language_enhancer_payload_excludes_raw_timeline_collection(): void
    {
        [$incident] = $this->createSerialPendingIncident();
        $snapshot = app(CaseIntelligenceEngine::class)->build($incident);

        $this->assertNotNull($snapshot);
        $payload = $snapshot->toLanguageEnhancerPayload();

        $this->assertArrayHasKey('current_status', $payload);
        $this->assertArrayHasKey('waiting', $payload);
        $this->assertArrayHasKey('blockers', $payload);
        $this->assertArrayHasKey('executive_summary', $payload);
        $this->assertArrayNotHasKey('timeline', $payload);
        $this->assertArrayNotHasKey('aiBundle', $payload);
        $this->assertArrayNotHasKey('context', $payload);
    }

    /**
     * @return array{0: Incident, 1: Order, 2: User}
     */
    private function createSerialPendingIncident(): array
    {
        $agent = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RD-CIE-SERIAL',
            'serial_number' => null,
            'product_name' => 'FM220',
            'device_model' => 'FM220',
            'customer_name' => 'CIE Customer',
            'customer_phone' => '9123456799',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Serial pending',
            'description' => 'Missing serial.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
            'created_at' => now()->subDays(2),
        ]);

        return [$incident->fresh(['order', 'assignee', 'activeWaitingState']), $order, $agent];
    }
}
