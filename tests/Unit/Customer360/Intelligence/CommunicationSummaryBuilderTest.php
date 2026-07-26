<?php

namespace Tests\Unit\Customer360\Intelligence;

use App\Data\AI\AIContextBuildSnapshot;
use App\Data\AI\CustomerJourneyConclusionDTO;
use App\Data\AI\CustomerJourneyConfidenceDTO;
use App\Data\AI\CustomerJourneyDTO;
use App\Data\Customer360\Intelligence\CaseIntelligenceFacts;
use App\Data\Customer360\Intelligence\CommunicationSummary;
use App\Data\TimelineActor;
use App\Data\TimelineEvent;
use App\Enums\AI\AIConfidenceLevel;
use App\Enums\AI\CustomerJourneyConclusionType;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\TimelineActorKind;
use App\Enums\TimelineEventType;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\AI\AIService;
use App\Services\AI\CustomerScopeQueryCache;
use App\Services\Customer360\Intelligence\Builders\CommunicationSummaryBuilder;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\CaseIntelligenceSnapshotFactory;
use Tests\TestCase;

class CommunicationSummaryBuilderTest extends TestCase
{
    use RefreshDatabase;

    private CommunicationSummaryBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->builder = app(CommunicationSummaryBuilder::class);
    }

    public function test_orders_communication_journey_chronologically(): void
    {
        $summary = $this->buildFromEvents([
            $this->whatsappTemplate('IRA', 'Appointment Reminder', 'Hindi', now()->subDays(4), TimelineActorKind::Automation),
            $this->whatsapp('Shubhanshi', 'Support Reminder Sent', now()->subDays(3)),
            $this->emailOutbound('Shubhanshi', 'Serial Number Required for Service', 'Please share the device serial number for service.', now()->subDays(2)),
            $this->whatsapp('Avinash', 'Support Reminder Sent', now()->subDays(1)),
            $this->callInbound('Sumit', now(), 'Customer promised to share the serial number today.'),
        ]);

        $this->assertCount(5, $summary->communicationJourney);
        $this->assertStringContainsString('Appointment Reminder', $summary->communicationJourney[0]->narrative);
        $this->assertStringContainsString('Hindi', $summary->communicationJourney[0]->narrative);
        $this->assertStringContainsString('Shubhanshi sent', $summary->communicationJourney[1]->narrative);
        $this->assertStringContainsString('Shubhanshi sent:', $summary->communicationJourney[2]->narrative);
        $this->assertStringContainsString('Avinash sent', $summary->communicationJourney[3]->narrative);
        $this->assertStringContainsString('Customer spoke with Sumit', $summary->communicationJourney[4]->narrative);
    }

    public function test_tracks_multiple_agents_in_briefing(): void
    {
        $summary = $this->buildFromEvents([
            $this->whatsappTemplate('IRA', 'Support Reminder', 'English', now()->subDays(3), TimelineActorKind::Automation),
            $this->whatsapp('Shubhanshi', 'Support Reminder Sent', now()->subDays(2)),
            $this->whatsapp('Avinash', 'Support Reminder Sent', now()->subDay()),
        ]);

        $this->assertContains('Shubhanshi', $summary->agentsInvolved);
        $this->assertContains('Avinash', $summary->agentsInvolved);
        $joined = implode(' ', $summary->briefingLines);
        $this->assertStringContainsString('IRA', $joined);
        $this->assertStringContainsString('Shubhanshi', $joined);
        $this->assertStringContainsString('Avinash', $joined);
        $this->assertStringContainsString('Customer has not replied.', $joined);
    }

    public function test_whatsapp_preview_is_truncated_to_100_chars(): void
    {
        $long = str_repeat('Serial will be shared tonight after work. ', 6);
        $summary = $this->buildFromEvents([
            $this->customerWhatsappReply($long, now()),
        ]);

        $this->assertNotNull($summary->latestWhatsapp?->preview);
        $this->assertLessThanOrEqual(CommunicationSummary::PREVIEW_MAX_CHARS, mb_strlen($summary->latestWhatsapp->preview));
        $this->assertStringEndsWith('…', $summary->latestWhatsapp->preview);
        $this->assertSame('inbound', $summary->customerLastReply?->direction);
        $this->assertStringContainsString('Customer replied:', $summary->latestWhatsapp->summary);
    }

    public function test_filters_whatsapp_transport_success_noise(): void
    {
        $summary = $this->buildFromEvents([
            $this->whatsappTransportNoise('IRA', now()->subHour()),
            $this->whatsappTemplate('IRA', 'Appointment Reminder', 'Hindi', now()),
        ]);

        $joined = implode(' ', $summary->briefingLines);
        $this->assertStringNotContainsString('template sent successfully', strtolower($joined));
        $this->assertStringNotContainsString('WhatsApp preview:', $joined);
        $this->assertStringContainsString('Appointment Reminder', $joined);
        $this->assertStringContainsString('Hindi', $joined);
        $this->assertNull($summary->latestWhatsapp?->preview);
    }

    public function test_email_subject_and_preview_extraction(): void
    {
        $summary = $this->buildFromEvents([
            $this->emailOutbound(
                'Shubhanshi',
                'Service Update - FM220',
                'Engineer has been assigned and will visit after serial confirmation.',
                now()->subDay(),
            ),
        ]);

        $this->assertSame('email', $summary->latestEmail?->channel);
        $this->assertSame('Service Update - FM220', $summary->latestEmail?->subject);
        $this->assertNotNull($summary->latestEmail?->preview);
        $this->assertLessThanOrEqual(
            CommunicationSummary::EMAIL_PREVIEW_MAX_CHARS,
            mb_strlen((string) $summary->latestEmail?->preview),
        );
        $joined = implode(' ', $summary->briefingLines);
        $this->assertStringContainsString('Shubhanshi sent:', $joined);
        $this->assertStringContainsString('Service Update - FM220', $joined);
        $this->assertStringContainsString('Preview:', $joined);
        $this->assertStringNotContainsString('Email sent successfully', $joined);
    }

    public function test_phone_summary_includes_agent_and_outcome(): void
    {
        $summary = $this->buildFromEvents([
            $this->callInbound('Sumit', now()->subHour(), 'Customer promised to share the serial number today.'),
        ]);

        $this->assertSame('phone', $summary->latestCall?->channel);
        $this->assertSame('Sumit', $summary->latestCall?->actorName);
        $this->assertStringContainsString('Customer spoke with Sumit', (string) $summary->latestCall?->summary);
        $this->assertStringContainsString('Outcome:', (string) $summary->latestCall?->summary);
        $this->assertContains('phone', $summary->channelsUsed);
    }

    public function test_duplicate_touchpoints_are_suppressed(): void
    {
        $at = now()->subDay()->seconds(0);
        $summary = $this->buildFromEvents([
            $this->whatsapp('Shubhanshi', 'Support Reminder Sent', $at),
            $this->whatsapp('Shubhanshi', 'Support Reminder Sent', $at->copy()),
        ]);

        $this->assertCount(1, $summary->touchpoints);
        $this->assertCount(1, $summary->communicationJourney);
    }

    public function test_empty_communication_returns_empty_summary(): void
    {
        $summary = $this->buildFromEvents([]);

        $this->assertTrue($summary->isEmpty());
        $this->assertNull($summary->latestWhatsapp);
        $this->assertNull($summary->latestEmail);
        $this->assertNull($summary->latestCall);
        $this->assertSame([], $summary->communicationJourney);
        $this->assertSame([], $summary->briefingLines);
        $this->assertNull($summary->briefingParagraph);
    }

    public function test_long_thread_is_truncated_with_earlier_marker(): void
    {
        $events = [];
        for ($i = 10; $i >= 1; $i--) {
            $events[] = $this->whatsapp('Agent '.$i, 'Support Reminder Sent', now()->subDays($i));
        }

        $summary = $this->buildFromEvents($events);

        $this->assertLessThanOrEqual(CommunicationSummary::JOURNEY_MAX_ENTRIES + 1, count($summary->communicationJourney));
        $this->assertSame('Earlier', $summary->communicationJourney[0]->dateLabel);
        $this->assertStringContainsString('earlier customer communication', $summary->communicationJourney[0]->narrative);
        $this->assertLessThanOrEqual(CommunicationSummary::JOURNEY_MAX_ENTRIES + 1, count($summary->briefingLines));
    }

    /**
     * @param  list<TimelineEvent>  $events
     */
    private function buildFromEvents(array $events): CommunicationSummary
    {
        [$incident, $order] = $this->createIncident();
        $timeline = CaseIntelligenceSnapshotFactory::timeline($events);
        $journey = new CustomerJourneyDTO(
            milestones: [],
            conclusion: new CustomerJourneyConclusionDTO(
                type: CustomerJourneyConclusionType::InProgress,
                headline: 'Active',
                detail: 'Active case',
                recommendation: 'Continue',
            ),
            confidence: new CustomerJourneyConfidenceDTO(
                score: 50,
                level: AIConfidenceLevel::Medium,
                positiveSignals: [],
                negativeSignals: [],
            ),
        );

        $facts = new CaseIntelligenceFacts(
            incident: $incident,
            order: $order,
            customerSummary: ['open_cases' => 1],
            activeServices: [],
            enrichmentMetadata: [],
            timeline: $timeline,
            waitingStateCard: null,
            supportAppointment: null,
            customerJourney: $journey,
            scopeCache: new CustomerScopeQueryCache($order->customer_phone),
            buildSnapshot: new AIContextBuildSnapshot(
                customerSummary: ['open_cases' => 1],
                activeServices: [],
                enrichmentMetadata: [],
                timeline: $timeline,
                waitingStateCard: null,
                supportAppointment: null,
                customerJourney: $journey,
            ),
        );

        $bundle = app(AIService::class)->buildBundle($incident);

        return $this->builder->build($facts, $bundle);
    }

    /**
     * @return array{0: Incident, 1: Order}
     */
    private function createIncident(): array
    {
        $agent = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RD-COMM-SUM-'.uniqid(),
            'serial_number' => 'SN-COMM-1',
            'product_name' => 'FM220',
            'device_model' => 'FM220',
            'customer_name' => 'Comm Customer',
            'customer_phone' => '9123456790',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Comm case',
            'description' => 'Communication fixture',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        return [$incident->fresh(['order', 'assignee']), $order];
    }

    private function whatsapp(
        string $actor,
        string $title,
        Carbon $at,
        TimelineActorKind $kind = TimelineActorKind::Agent,
    ): TimelineEvent {
        return new TimelineEvent(
            type: TimelineEventType::Notification,
            occurredAt: $at,
            title: $title,
            actor: new TimelineActor(
                displayName: $actor,
                isAutomation: $kind === TimelineActorKind::Automation,
                kind: $kind,
            ),
            dedupeKey: 'wa:'.uniqid(),
            communicationChannels: [
                ['label' => 'WhatsApp', 'success' => true],
            ],
        );
    }

    private function whatsappTemplate(
        string $actor,
        string $template,
        string $language,
        Carbon $at,
        TimelineActorKind $kind = TimelineActorKind::Agent,
    ): TimelineEvent {
        return new TimelineEvent(
            type: TimelineEventType::WhatsAppTemplateSent,
            occurredAt: $at,
            title: 'WhatsApp Template Sent',
            actor: new TimelineActor(
                displayName: $actor,
                isAutomation: $kind === TimelineActorKind::Automation,
                kind: $kind,
            ),
            dedupeKey: 'wa-tpl:'.uniqid(),
            summaryFields: [
                ['label' => 'Template', 'value' => $template],
                ['label' => 'Language', 'value' => $language],
            ],
        );
    }

    private function whatsappTransportNoise(string $actor, Carbon $at): TimelineEvent
    {
        return new TimelineEvent(
            type: TimelineEventType::Notification,
            occurredAt: $at,
            title: 'Support Reminder Sent',
            actor: new TimelineActor($actor, kind: TimelineActorKind::Automation, isAutomation: true),
            dedupeKey: 'wa-noise:'.uniqid(),
            summary: 'WhatsApp template sent successfully.',
            summaryFields: [
                ['label' => 'Preview', 'value' => 'WhatsApp template sent successfully.'],
            ],
            communicationChannels: [
                ['label' => 'WhatsApp', 'success' => true, 'detail' => 'WhatsApp template sent successfully.'],
            ],
        );
    }

    private function customerWhatsappReply(string $preview, Carbon $at): TimelineEvent
    {
        return new TimelineEvent(
            type: TimelineEventType::WhatsApp,
            occurredAt: $at,
            title: 'WhatsApp',
            actor: new TimelineActor('Customer', kind: TimelineActorKind::Customer),
            dedupeKey: 'wa-reply:'.uniqid(),
            summary: $preview,
            summaryFields: [
                ['label' => 'Preview', 'value' => $preview],
            ],
        );
    }

    private function emailOutbound(string $actor, string $subject, string $preview, Carbon $at): TimelineEvent
    {
        return new TimelineEvent(
            type: TimelineEventType::Notification,
            occurredAt: $at,
            title: 'Email Follow-up',
            actor: new TimelineActor($actor, kind: TimelineActorKind::Agent),
            dedupeKey: 'email:'.uniqid(),
            summary: $preview,
            summaryFields: [
                ['label' => 'Subject', 'value' => $subject],
                ['label' => 'Preview', 'value' => $preview],
            ],
            communicationChannels: [
                ['label' => 'Email', 'success' => true, 'detail' => $preview],
            ],
        );
    }

    private function callInbound(string $agent, Carbon $at, ?string $outcome = null): TimelineEvent
    {
        $fields = [
            ['label' => 'Agent', 'value' => $agent],
        ];
        if ($outcome !== null) {
            $fields[] = ['label' => 'Outcome', 'value' => $outcome];
        }

        return new TimelineEvent(
            type: TimelineEventType::IvrCall,
            occurredAt: $at,
            title: 'Inbound Call',
            actor: new TimelineActor('Customer → '.$agent, kind: TimelineActorKind::Customer),
            dedupeKey: 'call:'.uniqid(),
            summaryFields: $fields,
        );
    }
}
