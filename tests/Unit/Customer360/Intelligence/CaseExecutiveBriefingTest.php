<?php

namespace Tests\Unit\Customer360\Intelligence;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\WaitingReason;
use App\Models\Incident;
use App\Models\IncidentWaitingState;
use App\Models\Order;
use App\Models\User;
use App\Services\Customer360\Intelligence\CaseIntelligenceEngine;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CaseExecutiveBriefingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config(['ira.case_intelligence_engine.enabled' => true]);
    }

    public function test_executive_summary_is_narrative_without_rule_names(): void
    {
        [$incident, , $agent] = $this->createSerialWaitingIncident();
        $this->actingAs($agent);

        $snapshot = app(CaseIntelligenceEngine::class)->build($incident, true);
        $this->assertNotNull($snapshot);

        $text = implode(' ', $snapshot->executiveSummary->executiveSummary);

        $this->assertStringContainsString('service case for', strtolower($text));
        $this->assertStringContainsString('Next action:', $text);
        $this->assertStringNotContainsString('customer_silent', $text);
        $this->assertStringNotContainsString('waiting_too_long', $text);
        $this->assertStringNotContainsString('automation_stalled', $text);
        $this->assertStringNotContainsString('case_idle', $text);
        $this->assertStringNotContainsString('missing_mandatory_information', $text);
    }

    public function test_next_action_matches_canonical_recommendation(): void
    {
        [$incident, , $agent] = $this->createSerialWaitingIncident();
        $this->actingAs($agent);

        $snapshot = app(CaseIntelligenceEngine::class)->build($incident, true);
        $this->assertNotNull($snapshot);

        $canonical = (string) ($snapshot->recommendedAction->recommendationText
            ?? $snapshot->recommendedAction->label);
        $last = $snapshot->executiveSummary->executiveSummary[
            array_key_last($snapshot->executiveSummary->executiveSummary)
        ] ?? '';

        $this->assertSame($canonical, $snapshot->executiveSummary->recommendation);
        $this->assertSame('Next action: '.$canonical, $last);
        $this->assertNotNull($snapshot->communicationSummary);
        $this->assertSame(
            $snapshot->communicationSummary?->toArray(),
            $snapshot->toLanguageEnhancerPayload()['communication_summary'],
        );
    }

    public function test_empty_communication_omits_channel_mentions(): void
    {
        [$incident, , $agent] = $this->createSerialWaitingIncident();
        $this->actingAs($agent);

        $snapshot = app(CaseIntelligenceEngine::class)->build($incident, true);
        $this->assertNotNull($snapshot);
        $this->assertTrue($snapshot->communicationSummary?->isEmpty() ?? true);

        $text = implode(' ', $snapshot->executiveSummary->executiveSummary);
        $this->assertStringNotContainsString('WhatsApp preview:', $text);
        $this->assertStringNotContainsString('Email subject:', $text);
    }

    /**
     * @return array{0: Incident, 1: Order, 2: User}
     */
    private function createSerialWaitingIncident(): array
    {
        $agent = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RD-BRIEF-SERIAL',
            'serial_number' => null,
            'product_name' => 'FM220',
            'device_model' => 'FM220',
            'customer_name' => 'Brief Customer',
            'customer_phone' => '9123456791',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Serial pending briefing',
            'description' => 'Missing serial.',
            'status' => IncidentStatus::Open,
            'high_priority' => true,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
            'created_at' => now()->subDays(5),
        ]);

        IncidentWaitingState::query()->create([
            'incident_id' => $incident->id,
            'waiting_reason' => WaitingReason::SerialNumber,
            'started_at' => now()->subDays(5),
            'sla_paused' => true,
            'reminder_policy_key' => 'customer_waiting_default',
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);

        return [$incident->fresh(['order', 'assignee', 'activeWaitingState']), $order, $agent];
    }
}
