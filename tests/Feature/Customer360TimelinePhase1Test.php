<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\TimelineEventType;
use App\Enums\WaitingReason;
use App\Enums\WhatsAppTemplateDispatchStatus;
use App\Enums\WhatsAppTemplateTriggerSource;
use App\Models\Incident;
use App\Models\IncidentWaitingState;
use App\Models\Order;
use App\Models\User;
use App\Models\WhatsAppTemplateDispatch;
use App\Services\AuditLogService;
use App\Services\IncidentReferenceService;
use App\Services\Notifications\NotificationAuditTrailService;
use App\Services\RadiumBox\RadiumBoxSyncAuditService;
use App\Services\ServiceCaseAutomationMonitorService;
use App\Services\Timeline\Customer360TimelineService;
use App\Support\Timeline\TimelineActorPresenter;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class Customer360TimelinePhase1Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        config([
            'automation.display_name' => 'Ira',
            'automation.subtitle' => 'IRA AI',
            'cashfree.system_user_email' => 'superadmin@radium.local',
        ]);
    }

    public function test_notification_channels_merge_into_single_business_action_card(): void
    {
        [$agent, $incident] = $this->createFixture();

        app(AuditLogService::class)->log(
            userId: $agent->id,
            event: NotificationAuditTrailService::EVENT_DISPATCHED,
            auditable: $incident,
            newValues: [
                'notification_type' => 'request_serial_number',
                'aggregate_success' => true,
                'channel_results' => [
                    [
                        'channel' => 'email',
                        'status' => 'sent',
                        'success' => true,
                        'message' => 'Email delivered',
                    ],
                    [
                        'channel' => 'whatsapp',
                        'status' => 'sent',
                        'success' => true,
                        'message' => 'WhatsApp delivered',
                    ],
                ],
            ],
        );

        $events = $this->flattenTimeline(app(Customer360TimelineService::class)->forIncident($incident));
        $communication = $events->first(fn ($event) => $event->type === TimelineEventType::Notification);

        $this->assertNotNull($communication);
        $this->assertSame('Requested Device Serial Number', $communication->title);
        $this->assertNull($communication->contextLine);
        $this->assertCount(2, $communication->communicationChannels);
        $this->assertStringStartsWith('notification:request_serial_number:', (string) $communication->storyKey);
        $this->assertFalse($events->contains(fn ($event) => $event->type === TimelineEventType::Email));
        $this->assertFalse($events->contains(fn ($event) => $event->type === TimelineEventType::WhatsApp));
    }

    public function test_technical_sync_events_are_hidden_from_operator_timeline(): void
    {
        [$agent, $incident, $order] = $this->createFixture();

        app(AuditLogService::class)->log(
            userId: null,
            event: ServiceCaseAutomationMonitorService::EVENT_WAITING_RADIUMBOX,
            auditable: $incident,
            newValues: [],
        );

        app(AuditLogService::class)->log(
            userId: null,
            event: ServiceCaseAutomationMonitorService::EVENT_RADIUMBOX_VERIFIED,
            auditable: $incident,
            newValues: [],
        );

        app(AuditLogService::class)->log(
            userId: null,
            event: RadiumBoxSyncAuditService::EVENT_SCHEDULER_RECOVERY,
            auditable: $order,
            newValues: [],
        );

        $titles = $this->flattenTimeline(app(Customer360TimelineService::class)->forIncident($incident))
            ->map(fn ($event) => $event->title)
            ->all();

        $this->assertNotContains('Background sync started', $titles);
        $this->assertNotContains('Background sync completed', $titles);
        $this->assertNotContains('Recovery retry dispatched', $titles);
        $this->assertNotContains('Order created', $titles);
    }

    public function test_support_request_created_is_visible_with_operator_title(): void
    {
        [, $incident] = $this->createFixture();

        $titles = $this->flattenTimeline(app(Customer360TimelineService::class)->forIncident($incident))
            ->map(fn ($event) => $event->title)
            ->all();

        $this->assertContains('Support Request Created', $titles);
    }

    public function test_waiting_lifecycle_replaces_fragmented_waiting_communications(): void
    {
        [$agent, $incident] = $this->createFixture();

        $waitingState = IncidentWaitingState::query()->create([
            'incident_id' => $incident->id,
            'waiting_reason' => WaitingReason::SerialNumber,
            'started_at' => now()->subHour(),
            'customer_followup_sent_at' => now()->subMinutes(20),
            'sla_paused' => true,
            'reminder_policy_key' => 'customer_waiting_default',
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);

        app(AuditLogService::class)->log(
            userId: null,
            event: NotificationAuditTrailService::EVENT_DISPATCHED,
            auditable: $incident,
            newValues: [
                'notification_type' => 'customer_waiting_followup',
                'aggregate_success' => true,
                'channel_results' => [[
                    'channel' => 'whatsapp',
                    'status' => 'sent',
                    'success' => true,
                ]],
            ],
        );

        WhatsAppTemplateDispatch::query()->create([
            'incident_id' => $incident->id,
            'order_id' => $incident->order_id,
            'triggered_by_user_id' => $agent->id,
            'template_key' => 'customer_waiting_followup',
            'template_name' => 'support_schedule_followup',
            'template_display_name' => 'Support Reminder',
            'template_purpose' => 'Customer waiting follow-up',
            'trigger_source' => WhatsAppTemplateTriggerSource::Automation,
            'status' => WhatsAppTemplateDispatchStatus::Sent,
            'customer_phone' => '9123456780',
            'dispatched_at' => now()->subMinutes(20),
        ]);

        $events = $this->flattenTimeline(app(Customer360TimelineService::class)->forIncident($incident));
        $titles = $events->map(fn ($event) => $event->title)->all();

        $this->assertContains('Waiting for Customer', $titles);
        $this->assertNotContains('Support Reminder Sent', $titles);

        $waitingCard = $events->first(fn ($event) => $event->dedupeKey === "waiting-lifecycle:{$waitingState->id}");
        $this->assertNotNull($waitingCard);
        $this->assertSame('Awaiting: Device Serial Number', $waitingCard->contextLine);
        $this->assertSame(TimelineActorPresenter::IRA_DISPLAY_NAME, $waitingCard->actor->displayName);
    }

    public function test_timeline_groups_events_by_operator_date_labels(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 14:00:00', 'Asia/Kolkata'));

        [$agent, $incident] = $this->createFixture();

        $viewModel = app(Customer360TimelineService::class)->forIncident($incident);
        $labels = $viewModel->groups->map(fn ($group) => $group->label())->all();

        $this->assertContains('Today', $labels);
    }

    public function test_automation_actor_displays_as_ira_in_operator_timeline(): void
    {
        [$agent, $incident, $order] = $this->createFixture();

        $order->update([
            'payment_date' => now(),
            'payment_amount' => 499,
            'payment_method' => 'UPI',
        ]);

        $events = $this->flattenTimeline(app(Customer360TimelineService::class)->forIncident($incident));
        $payment = $events->first(fn ($event) => $event->type === TimelineEventType::Payment);

        $this->assertNotNull($payment);
        $this->assertSame(TimelineActorPresenter::IRA_DISPLAY_NAME, $payment->actor->displayName);
        $this->assertNull($payment->actor->subtitle);
    }

    /**
     * @return array{0: User, 1: Incident, 2: Order}
     */
    private function createFixture(): array
    {
        $agent = User::factory()->create(['first_name' => 'Priya']);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-PHASE1-'.uniqid(),
            'customer_phone' => '9123456780',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Phase 1 timeline case',
            'description' => 'Phase 1 timeline case.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);

        return [$agent, $incident, $order];
    }

    private function flattenTimeline(\App\Data\TimelineViewModel $viewModel): \Illuminate\Support\Collection
    {
        return $viewModel->groups->flatMap(fn ($group) => $group->events)->values();
    }
}
