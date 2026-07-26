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

        $this->assertNull($constructor?->getNumberOfParameters() ?: null);
        $this->assertSame(0, $constructor?->getNumberOfParameters() ?? 0);
    }

    public function test_present_builds_scannable_panel_view_model_from_snapshot(): void
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
        $this->assertSame('Case intelligence', $panel['subtitle']);
        $this->assertNotEmpty($panel['executive_summary_lines']);
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
