<?php

namespace Tests\Unit\Customer360;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\Customer360\Intelligence\CaseIntelligenceEngine;
use App\Services\IncidentReferenceService;
use App\Support\Customer360\Customer360IraPanelPresenter;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

class Customer360IraPanelPresenterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config(['ira.case_intelligence_engine.enabled' => true]);
    }

    public function test_present_maps_snapshot_without_domain_service_dependencies(): void
    {
        $reflection = new ReflectionClass(Customer360IraPanelPresenter::class);
        $constructor = $reflection->getConstructor();
        $params = $constructor?->getParameters() ?? [];

        // Presenter may depend on pure formatting helpers only — never domain services.
        foreach ($params as $param) {
            $type = $param->getType()?->getName();
            $this->assertNotNull($type);
            $this->assertStringStartsWith('App\\Support\\Customer360\\', $type);
            $this->assertStringNotContainsString('Service', class_basename($type));
        }
    }

    public function test_present_emphasizes_people_and_renders_communication_block(): void
    {
        [$incident, , $agent] = $this->createIncident();
        $agent->forceFill(['name' => 'Jayram'])->save();
        $incident->forceFill(['assigned_to_user_id' => $agent->id])->save();
        $this->actingAs($agent);

        $snapshot = app(CaseIntelligenceEngine::class)->build($incident->fresh(['order', 'assignee']));
        $this->assertNotNull($snapshot);

        $panel = app(Customer360IraPanelPresenter::class)->present(
            snapshot: $snapshot,
            incident: $incident->fresh(['assignee']),
        );

        $this->assertTrue($panel['executive_summary_allows_html']);
        $this->assertNotEmpty($panel['executive_brief']);
        $this->assertStringContainsString('c360-ira-person', $panel['executive_narrative_html']);
        $this->assertStringContainsString('Jayram', strip_tags($panel['executive_narrative_html']));
        $this->assertTrue($panel['has_contributors']);
        $this->assertSame('Assigned To', $panel['case_contributors'][0]['role']);
        $this->assertSame('Jayram', $panel['case_contributors'][0]['name']);
        // Plain payload for translation must not include markup.
        foreach ($panel['summary_payload']['executive_summary'] as $plainLine) {
            $this->assertStringNotContainsString('<strong', $plainLine);
        }
    }

    public function test_present_builds_operations_briefing_hierarchy(): void
    {
        [$incident, , $agent] = $this->createIncident();
        $this->actingAs($agent);

        $snapshot = app(CaseIntelligenceEngine::class)->build($incident);
        $this->assertNotNull($snapshot);

        $panel = app(Customer360IraPanelPresenter::class)->present(
            snapshot: $snapshot,
            incident: $incident,
            translateUrl: 'https://example.test/translate',
        );

        $this->assertSame('IRA', $panel['heading']);
        $this->assertSame('Operations briefing', $panel['subtitle']);
        $this->assertNotEmpty($panel['executive_brief']);
        $this->assertNotSame('', $panel['executive_narrative_plain']);
        $this->assertArrayHasKey('communication_items', $panel);
        $this->assertArrayHasKey('customer_journey_items', $panel);
        $this->assertArrayHasKey('action_center', $panel);
        $this->assertArrayHasKey('primary_label', $panel['action_center']);
        $this->assertArrayHasKey('checklist', $panel['action_center']);
        $this->assertArrayHasKey('quick_actions', $panel['action_center']);
        $this->assertArrayHasKey('case_contributors', $panel);
        $this->assertArrayHasKey('label', $panel['current_status']);
        $this->assertContains($panel['waiting']['party'], ['Customer', 'Engineer', 'Internal Team', 'Nobody']);
        $this->assertIsArray($panel['blockers']);
        $this->assertIsArray($panel['risks']);
        $this->assertNotSame('', $panel['recommended_action']['text']);
        $this->assertIsArray($panel['evidence']);
        $this->assertArrayHasKey('summary_payload', $panel);
        $canonical = (string) ($snapshot->recommendedAction->recommendationText
            ?? $snapshot->recommendedAction->label);
        $this->assertSame($canonical, $panel['summary_payload']['recommendation']);
        $this->assertSame($canonical, $panel['recommended_action']['text']);
        $this->assertSame($canonical, $snapshot->executiveSummary->recommendation);

        $briefLabels = array_column($panel['executive_brief'], 'label');
        $this->assertContains('Current Stage', $briefLabels);
        $this->assertContains('Product', $briefLabels);

        foreach ($panel['evidence'] as $item) {
            $this->assertArrayHasKey('title', $item);
            $this->assertArrayHasKey('source', $item);
            $this->assertArrayHasKey('tone', $item);
            $this->assertArrayHasKey('anchor', $item);
        }
    }

    /**
     * @return array{0: Incident, 1: Order, 2: User}
     */
    private function createIncident(): array
    {
        $agent = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RD-IRA-PANEL',
            'serial_number' => null,
            'product_name' => 'FM220',
            'device_model' => 'FM220',
            'customer_name' => 'Panel Customer',
            'customer_phone' => '9123456702',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Panel case',
            'description' => 'Missing serial.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        return [$incident->fresh(['order', 'assignee', 'activeWaitingState']), $order, $agent];
    }
}
