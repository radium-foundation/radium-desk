<?php

namespace Tests\Feature;

use App\Data\IncomingEmail\NormalizedInboundEmail;
use App\Enums\IncomingEmailMessageStatus;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\TeamAvailabilityStatus;
use App\Enums\TimelineEventType;
use App\Models\Incident;
use App\Models\IncidentIncomingEmailLink;
use App\Models\IncomingEmailMessage;
use App\Models\Order;
use App\Models\User;
use App\Models\WorkSession;
use App\Services\IncomingEmail\IncomingEmailIngestService;
use App\Services\Operations\PresenceEngineService;
use App\Services\SettingService;
use App\Services\Timeline\Sources\IncomingEmailTimelineEventSource;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class IncomingEmailIntakePhase1Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'inbound_email.enabled' => true,
            'inbound_email.ignored_labels' => [
                'SPAM',
                'TRASH',
                'CATEGORY_PROMOTIONS',
                'CATEGORY_SOCIAL',
            ],
            'inbound_email.system_sender_patterns' => [
                'mailer-daemon@',
                'mail-daemon@',
                'postmaster@',
                'noreply@',
                'no-reply@',
                'donotreply@',
                'do-not-reply@',
                'bounce@',
                'bounces@',
            ],
            'inbound_email.system_from_names' => [
                'mail delivery subsystem',
                'mail delivery system',
                'mailer-daemon',
                'postmaster',
            ],
            'inbound_email.auto_responder_header_tokens' => [
                'auto-submitted',
                'x-autoreply',
                'x-autorespond',
                'x-auto-response-suppress',
                'precedence',
                'list-unsubscribe',
                'list-id',
            ],
            'inbound_email.mailboxes' => [
                'support@radiumbox.com' => 'support',
            ],
            'inbound_email.preview_max_chars' => 280,
            'inbound_email.blocked_senders' => [],
            'inbound_email.blocked_domains' => [],
            'cashfree.system_user_email' => 'superadmin@radium.local',
            'service_case_assignment.automation_grace_period_enabled' => true,
            'service_case_assignment.round_robin_enabled' => true,
        ]);

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        User::factory()->create([
            'name' => 'System',
            'email' => 'superadmin@radium.local',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_feature_flag_disabled_does_not_ingest(): void
    {
        config(['inbound_email.enabled' => false]);

        $message = $this->ingestEmail(fromEmail: 'customer@example.com');

        $this->assertNull($message);
        $this->assertSame(0, IncomingEmailMessage::query()->count());
    }

    public function test_dedupes_on_rfc_message_id(): void
    {
        [$order, $incident] = $this->seedCustomerWithOpenIncident('customer@example.com');

        $first = $this->ingestEmail(
            fromEmail: 'customer@example.com',
            rfcMessageId: '<same-id@radium.test>',
            providerMessageId: 'prov-1',
        );
        $second = $this->ingestEmail(
            fromEmail: 'customer@example.com',
            rfcMessageId: '<same-id@radium.test>',
            providerMessageId: 'prov-2',
        );

        $this->assertNotNull($first);
        $this->assertSame($first->id, $second?->id);
        $this->assertSame(1, IncomingEmailMessage::query()->count());
        $this->assertSame(1, IncidentIncomingEmailLink::query()->where('incident_id', $incident->id)->count());
        $this->assertSame(1, Incident::query()->count());
        $this->assertSame($order->id, $incident->fresh()->order_id);
    }

    public function test_spam_label_is_ignored(): void
    {
        $this->seedCustomerWithOpenIncident('customer@example.com');

        $message = $this->ingestEmail(
            fromEmail: 'customer@example.com',
            labels: ['SPAM'],
        );

        $this->assertSame(IncomingEmailMessageStatus::Ignored, $message?->status);
        $this->assertSame('spam', $message?->ignore_reason);
        $this->assertSame(0, IncidentIncomingEmailLink::query()->count());
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'incoming_email.ignored',
            'auditable_id' => $message->id,
        ]);
    }

    public function test_bounce_is_ignored(): void
    {
        $this->seedCustomerWithOpenIncident('customer@example.com');

        $message = $this->ingestEmail(
            fromEmail: 'mailer-daemon@google.com',
            fromName: 'Mail Delivery Subsystem',
            subject: 'Delivery Status Notification (Failure)',
        );

        $this->assertSame(IncomingEmailMessageStatus::Ignored, $message?->status);
        $this->assertNotNull($message?->ignore_reason);
        $this->assertSame(0, IncidentIncomingEmailLink::query()->count());
    }

    public function test_auto_responder_is_ignored(): void
    {
        $this->seedCustomerWithOpenIncident('customer@example.com');

        $message = $this->ingestEmail(
            fromEmail: 'customer@example.com',
            subject: 'Out of Office: Away',
            headers: ['Auto-Submitted' => 'auto-replied'],
        );

        $this->assertSame(IncomingEmailMessageStatus::Ignored, $message?->status);
        $this->assertSame('auto_responder', $message?->ignore_reason);
    }

    public function test_unknown_customer_is_ignored_without_creating_incident(): void
    {
        $before = Incident::query()->count();

        $message = $this->ingestEmail(fromEmail: 'unknown@example.com');

        $this->assertSame(IncomingEmailMessageStatus::Ignored, $message?->status);
        $this->assertSame('unknown_customer', $message?->ignore_reason);
        $this->assertSame($before, Incident::query()->count());
        $this->assertSame(0, IncidentIncomingEmailLink::query()->count());
    }

    public function test_known_customer_without_open_incident_is_ignored(): void
    {
        $this->seedCustomerOrder('customer@example.com');

        $before = Incident::query()->count();

        $message = $this->ingestEmail(fromEmail: 'customer@example.com');

        $this->assertSame(IncomingEmailMessageStatus::Ignored, $message?->status);
        $this->assertSame('no_open_incident', $message?->ignore_reason);
        $this->assertSame($before, Incident::query()->count());
        $this->assertSame(0, IncidentIncomingEmailLink::query()->count());
    }

    public function test_known_customer_with_open_incident_is_linked(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-18 22:00:00', 'Asia/Kolkata'));

        $nightAdmin = $this->createAdminUser('night-admin@test.com', 'Night Admin');
        $this->configureAssignmentSettings($nightAdmin->id, $nightAdmin->id);

        [$order, $incident] = $this->seedCustomerWithOpenIncident('customer@example.com');

        $message = $this->ingestEmail(
            fromEmail: 'customer@example.com',
            subject: 'Need help with my device',
            preview: 'The fingerprint sensor stopped working.',
            attachmentCount: 2,
            threadId: 'thread-abc',
        );

        $this->assertSame(IncomingEmailMessageStatus::Linked, $message?->status);
        $this->assertSame($incident->id, $message?->incident_id);

        $this->assertDatabaseHas('incident_incoming_email_links', [
            'incident_id' => $incident->id,
            'incoming_email_message_id' => $message->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'incoming_email.received',
            'auditable_id' => $message->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'incoming_email.linked',
            'auditable_id' => $incident->id,
        ]);

        $incident = $incident->fresh();
        $this->assertTrue($incident->high_priority);
        $this->assertSame($nightAdmin->id, $incident->assigned_to_user_id);

        $timeline = (new IncomingEmailTimelineEventSource($order))->collect();
        $this->assertCount(1, $timeline);
        $this->assertSame(TimelineEventType::Email, $timeline->first()->type);
        $this->assertSame('Incoming Email', $timeline->first()->title);
        $this->assertSame('incoming_email:'.$message->id, $timeline->first()->dedupeKey);

        $this->assertSame(1, Incident::query()->count());
    }

    public function test_already_assigned_incident_keeps_assignee(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-18 10:00:00', 'Asia/Kolkata'));

        $dayAdmin = $this->createAdminUser('day-admin@test.com', 'Day Admin');
        $nightAdmin = $this->createAdminUser('night-admin@test.com', 'Night Admin');
        $this->configureAssignmentSettings($dayAdmin->id, $nightAdmin->id);
        $agent = $this->createEligibleAgent('agent@test.com', 'Support Agent');

        [$order, $incident] = $this->seedCustomerWithOpenIncident(
            email: 'customer@example.com',
            assignedToUserId: $agent->id,
        );

        $this->ingestEmail(fromEmail: 'customer@example.com');

        $this->assertSame($agent->id, $incident->fresh()->assigned_to_user_id);
        $this->assertTrue($incident->fresh()->high_priority);
        $this->assertSame($order->id, $incident->fresh()->order_id);
    }

    public function test_inbound_email_does_not_modify_last_business_action_at(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-18 09:30:00', 'Asia/Kolkata'));

        $agent = $this->createEligibleAgent('agent-workforce@test.com', 'Workforce Agent');
        $session = WorkSession::query()->where('user_id', $agent->id)->whereNull('logout_at')->first();
        $this->assertNotNull($session);

        $session->update([
            'last_business_action' => 'case.action',
            'last_business_action_at' => Carbon::parse('2026-07-18 09:29:00', 'Asia/Kolkata'),
        ]);

        $snapshotAction = $session->fresh()->last_business_action;
        $snapshotAt = $session->fresh()->last_business_action_at?->toIso8601String();

        $this->seedCustomerWithOpenIncident(
            email: 'customer@example.com',
            assignedToUserId: $agent->id,
        );

        Carbon::setTestNow(Carbon::parse('2026-07-18 09:35:00', 'Asia/Kolkata'));
        $this->ingestEmail(fromEmail: 'customer@example.com');

        $session = $session->fresh();
        $this->assertSame($snapshotAction, $session->last_business_action);
        $this->assertSame($snapshotAt, $session->last_business_action_at?->toIso8601String());
    }

    public function test_thread_id_links_to_prior_incident(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-18 22:00:00', 'Asia/Kolkata'));

        $nightAdmin = $this->createAdminUser('night-admin-thread@test.com', 'Night Admin');
        $this->configureAssignmentSettings($nightAdmin->id, $nightAdmin->id);

        [, $incident] = $this->seedCustomerWithOpenIncident('customer@example.com');

        $this->ingestEmail(
            fromEmail: 'customer@example.com',
            rfcMessageId: '<thread-first@radium.test>',
            providerMessageId: 'thread-prov-1',
            threadId: 'thread-shared',
        );

        // Closed the order path would normally miss; thread continuity should still find the incident.
        $followUp = $this->ingestEmail(
            fromEmail: 'alias-unknown@elsewhere.com',
            rfcMessageId: '<thread-second@radium.test>',
            providerMessageId: 'thread-prov-2',
            threadId: 'thread-shared',
            subject: 'Re: Need help',
        );

        $this->assertSame(IncomingEmailMessageStatus::Linked, $followUp?->status);
        $this->assertSame($incident->id, $followUp?->incident_id);
    }

    /**
     * @param  list<string>  $labels
     * @param  array<string, string>  $headers
     */
    private function ingestEmail(
        string $fromEmail,
        ?string $fromName = 'Customer',
        ?string $subject = 'Support request',
        ?string $preview = 'Hello, I need help.',
        ?string $rfcMessageId = null,
        ?string $providerMessageId = null,
        ?string $threadId = null,
        int $attachmentCount = 0,
        array $labels = [],
        array $headers = [],
        string $mailbox = 'support@radiumbox.com',
    ): ?IncomingEmailMessage {
        $unique = uniqid('email-', true);
        $dto = new NormalizedInboundEmail(
            mailbox: $mailbox,
            provider: 'fixture',
            providerMessageId: $providerMessageId ?? $unique,
            rfcMessageId: $rfcMessageId ?? '<'.$unique.'@radium.test>',
            threadId: $threadId,
            fromEmail: $fromEmail,
            fromName: $fromName,
            toEmails: [$mailbox],
            subject: $subject,
            preview: $preview,
            receivedAt: now(),
            attachmentCount: $attachmentCount,
            headers: $headers,
            labels: $labels,
            rawPayload: ['fixture' => true],
        );

        return app(IncomingEmailIngestService::class)->ingest($dto);
    }

    /**
     * @return array{0: Order, 1: Incident}
     */
    private function seedCustomerWithOpenIncident(
        string $email,
        ?int $assignedToUserId = null,
    ): array {
        $order = $this->seedCustomerOrder($email);
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-EMAIL-'.uniqid(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Open support case',
            'description' => 'Existing open incident for email intake tests.',
            'status' => IncidentStatus::Open,
            'high_priority' => false,
            'assigned_to_user_id' => $assignedToUserId,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
        ]);

        return [$order, $incident];
    }

    private function seedCustomerOrder(string $email): Order
    {
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_AGENT);

        return Order::query()->create([
            'order_id' => 'RD-EMAIL-'.uniqid(),
            'serial_number' => 'SN-EMAIL-'.uniqid(),
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Email Customer',
            'customer_phone' => '9876501234',
            'customer_email' => $email,
            'status' => 'active',
            'created_by' => $creator->id,
        ]);
    }

    private function configureAssignmentSettings(int $dayAdminId, int $nightAdminId): void
    {
        app(SettingService::class)->setMany([
            'assignment.timezone' => 'Asia/Kolkata',
            'assignment.day_shift_start' => '09:00',
            'assignment.day_shift_end' => '18:30',
            'assignment.day_shift_admin_user_id' => (string) $dayAdminId,
            'assignment.night_shift_admin_user_id' => (string) $nightAdminId,
            'assignment.fallback_admin_1_user_id' => '',
            'assignment.fallback_admin_2_user_id' => '',
        ]);
    }

    private function createAdminUser(string $email, string $name): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => $email,
            'is_active' => true,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $user;
    }

    private function createEligibleAgent(string $email, string $name): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => $email,
            'is_active' => true,
            'availability_status' => TeamAvailabilityStatus::Available,
            'availability_updated_at' => now(),
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);
        app(PresenceEngineService::class)->startSession($user);

        return $user->fresh();
    }
}
