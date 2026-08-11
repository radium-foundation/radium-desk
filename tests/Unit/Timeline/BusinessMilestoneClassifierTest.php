<?php

namespace Tests\Unit\Timeline;

use App\Data\TimelineActor;
use App\Data\TimelineEvent;
use App\Enums\BusinessMilestoneType;
use App\Enums\TimelineActorKind;
use App\Enums\TimelineEventType;
use App\Support\Timeline\BusinessMilestoneClassifier;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BusinessMilestoneClassifierTest extends TestCase
{
    private BusinessMilestoneClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = app(BusinessMilestoneClassifier::class);
    }

    #[DataProvider('classificationProvider')]
    public function test_classifies_events_to_expected_milestones(
        TimelineEventType $type,
        string $title,
        string $dedupeKey,
        BusinessMilestoneType $expected,
        ?TimelineActorKind $actorKind = null,
        ?string $storyKey = null,
    ): void {
        $event = new TimelineEvent(
            type: $type,
            occurredAt: now(),
            title: $title,
            actor: new TimelineActor(
                displayName: $actorKind === TimelineActorKind::Customer ? 'Customer' : 'IRA',
                kind: $actorKind,
            ),
            dedupeKey: $dedupeKey,
            storyKey: $storyKey,
        );

        $this->assertSame($expected, $this->classifier->classify($event));
    }

    /**
     * @return array<string, array{0: TimelineEventType, 1: string, 2: string, 3: BusinessMilestoneType, 4?: TimelineActorKind|null, 5?: string|null}>
     */
    public static function classificationProvider(): array
    {
        return [
            'payment' => [TimelineEventType::Payment, 'Payment received', 'payment:1', BusinessMilestoneType::PaymentReceived],
            'appointment' => [TimelineEventType::Appointment, 'Appointment booked', 'appointment:1', BusinessMilestoneType::Appointment],
            'whatsapp_template' => [TimelineEventType::WhatsAppTemplateSent, 'whatsapp_template_sent', 'wa:1', BusinessMilestoneType::OutboundWhatsApp],
            'waiting_started' => [TimelineEventType::AuditEvent, 'Waiting started', 'waiting-lifecycle:start:1', BusinessMilestoneType::WaitingStarted],
            'waiting_cleared' => [TimelineEventType::AuditEvent, 'Waiting cleared', 'waiting-lifecycle:cleared:1', BusinessMilestoneType::WaitingCleared],
            'sla' => [TimelineEventType::AuditEvent, 'SLA overdue', 'sla:breach:1', BusinessMilestoneType::SlaBreached],
            'customer_email' => [TimelineEventType::Email, 'Customer replied', 'incoming_email:9', BusinessMilestoneType::CustomerReply, TimelineActorKind::Customer],
            'assignment' => [TimelineEventType::Assignment, 'Assigned to Sushant', 'assignment:1', BusinessMilestoneType::EngineerAssignment],
            'case_created' => [TimelineEventType::ServiceCaseCreated, 'Service request created', 'case:1', BusinessMilestoneType::CaseCreated],
            'case_closed' => [TimelineEventType::ServiceCaseClosed, 'Incident closed', 'incident-status:1', BusinessMilestoneType::Closure],
            'serial_number_added' => [TimelineEventType::AuditEvent, 'Serial Number Added', 'audit:1100666', BusinessMilestoneType::SerialVerified],
            'serial_assigned_sync' => [TimelineEventType::Synchronization, 'Serial assigned', 'serial-assigned:1100666', BusinessMilestoneType::SerialVerified],
            'device_model_assigned' => [TimelineEventType::AuditEvent, 'Device Model Assigned', 'audit:1097215', BusinessMilestoneType::SystemUpdate],
            'appointment_notification' => [TimelineEventType::Notification, 'Support appointment booked Sent', 'notification:audit:1', BusinessMilestoneType::OutboundEmail],
            'internal_note' => [TimelineEventType::InternalNote, 'Internal note', 'note:1', BusinessMilestoneType::InternalNote],
        ];
    }

    public function test_cluster_family_keeps_calls_with_different_actors_separate(): void
    {
        $sushant = new TimelineEvent(
            type: TimelineEventType::IvrCall,
            occurredAt: Carbon::parse('2026-07-01 10:00:00', 'Asia/Kolkata'),
            title: 'Call with Sushant',
            actor: new TimelineActor('Sushant'),
            dedupeKey: 'call:1',
        );
        $ravi = new TimelineEvent(
            type: TimelineEventType::IvrCall,
            occurredAt: Carbon::parse('2026-07-01 11:00:00', 'Asia/Kolkata'),
            title: 'Call with Ravi',
            actor: new TimelineActor('Ravi'),
            dedupeKey: 'call:2',
        );

        $type = BusinessMilestoneType::OutboundCall;

        $this->assertNotSame(
            $this->classifier->clusterFamily($sushant, $type),
            $this->classifier->clusterFamily($ravi, $type),
        );
    }
}
