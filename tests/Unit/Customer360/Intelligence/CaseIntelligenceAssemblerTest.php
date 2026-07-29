<?php

namespace Tests\Unit\Customer360\Intelligence;

use App\Data\Customer360\Intelligence\CaseIntelligence;
use App\Data\Customer360\Intelligence\CaseIntelligenceRisk;
use App\Data\Customer360\Intelligence\CaseStory;
use App\Data\Customer360\Intelligence\CommunicationSummary;
use App\Data\Customer360\Intelligence\CommunicationTouchpoint;
use App\Enums\AI\AIRiskLevel;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Support\Customer360\CaseIntelligenceAssembler;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\CaseIntelligenceSnapshotFactory;
use Tests\TestCase;

class CaseIntelligenceAssemblerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_v2_feature_flag_defaults_to_disabled(): void
    {
        $assembler = app(CaseIntelligenceAssembler::class);

        $this->assertFalse($assembler->enabled());
        $this->assertFalse((bool) config('ira.v2.enabled'));
    }

    public function test_v2_feature_flag_can_be_enabled(): void
    {
        config(['ira.v2.enabled' => true]);

        $this->assertTrue(app(CaseIntelligenceAssembler::class)->enabled());
    }

    public function test_from_snapshot_projects_signal_fields_without_recomputing_rules(): void
    {
        $agent = User::factory()->create(['name' => 'Priya Agent']);
        $order = Order::query()->create([
            'order_id' => 'RD-IRA-V2-1',
            'product_name' => 'FM220',
            'device_model' => 'FM220',
            'customer_name' => 'Signal Customer',
            'customer_email' => 'signal@example.com',
            'customer_phone' => '9111111111',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);
        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-IRA-V2-1',
            'category' => 'General',
            'title' => 'Assembler case',
            'description' => 'Projection test',
            'status' => 'open',
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        $now = Carbon::parse('2026-07-29 10:00:00');
        $touchpoints = [
            new CommunicationTouchpoint('whatsapp', $now, 'inbound', 'Customer', 'WA in', 'hi'),
            new CommunicationTouchpoint('whatsapp', $now->copy()->addMinute(), 'outbound', 'Priya', 'WA out', 'ok'),
            new CommunicationTouchpoint('email', $now->copy()->addMinutes(2), 'inbound', 'Customer', 'Mail', 'hello'),
            new CommunicationTouchpoint('phone', $now->copy()->addMinutes(3), 'inbound', 'Customer', 'Call', null),
            new CommunicationTouchpoint('telegram', $now->copy()->addMinutes(4), 'outbound', 'System', 'TG', null),
        ];

        $snapshot = CaseIntelligenceSnapshotFactory::make([
            'incidentId' => $incident->id,
            'orderId' => $order->id,
            'currentStatusLabel' => 'Waiting on customer',
            'isWaiting' => true,
            'waitingParty' => 'customer',
            'waitingSince' => $now->copy()->subDays(2),
            'engineerName' => 'Priya Agent',
            'customerMoodLevel' => 'unknown',
            'confidenceScore' => 72,
            'risks' => [
                new CaseIntelligenceRisk(
                    key: 'customer_silent',
                    label: 'Customer has been silent',
                    category: 'communication',
                    severity: AIRiskLevel::High,
                ),
                new CaseIntelligenceRisk(
                    key: 'idle',
                    label: 'Case idle',
                    category: 'ops',
                    severity: AIRiskLevel::Low,
                ),
            ],
            'caseStory' => new CaseStory(
                currentSituation: ['Waiting for customer serial.'],
                progress: ['Case opened.'],
                blockers: ['Serial missing.'],
                risks: ['Customer silent.'],
                recommendedAction: ['Request serial via WhatsApp.'],
                supportingFacts: ['Assigned to Priya Agent.'],
            ),
            'communicationSummary' => new CommunicationSummary(
                latestWhatsapp: $touchpoints[1],
                latestEmail: $touchpoints[2],
                latestCall: $touchpoints[3],
                communicationJourney: [],
                customerLastReply: $touchpoints[0],
                ourLastContact: $touchpoints[1],
                channelsUsed: ['whatsapp', 'email', 'phone', 'telegram'],
                agentsInvolved: ['Priya'],
                touchpoints: $touchpoints,
                briefingParagraph: 'Customer messaged on WhatsApp.',
            ),
            'openQuestions' => ['Serial number'],
        ]);

        $aggregate = app(CaseIntelligenceAssembler::class)->fromSnapshot($snapshot, $incident);

        $this->assertInstanceOf(CaseIntelligence::class, $aggregate);
        $this->assertSame(CaseIntelligence::SCHEMA_VERSION, $aggregate->schemaVersion);
        $this->assertSame('Waiting on customer', $aggregate->currentStatusLabel);
        $this->assertSame('Priya Agent', $aggregate->assignedAgentName);
        $this->assertSame('Unknown', $aggregate->sentimentLabel);
        $this->assertSame('high', $aggregate->riskLevel);
        $this->assertSame([
            'whatsapp' => 2,
            'email' => 1,
            'phone' => 1,
            'telegram' => 1,
        ], $aggregate->communicationCounts);
        $this->assertSame(['Serial number'], $aggregate->openQuestions);
        $this->assertNotNull($aggregate->customerStory);
        $this->assertSame('Follow up with the customer.', $aggregate->nextBestAction->recommendationText);
        $this->assertSame(72, $aggregate->confidenceScore);
    }
}
