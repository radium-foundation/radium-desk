<?php

namespace Tests\Feature\IncomingEmail;

use App\Data\IncomingEmail\NormalizedInboundEmail;
use App\Enums\IncomingEmailMessageStatus;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\OutgoingEmailMessageStatus;
use App\Enums\TeamAvailabilityStatus;
use App\Enums\TimelineEventType;
use App\Models\Incident;
use App\Models\IncomingEmailMessage;
use App\Models\Order;
use App\Models\OutgoingEmailMessage;
use App\Models\User;
use App\Services\IncomingEmail\Gmail\GmailAccessTokenService;
use App\Services\IncomingEmail\IncomingEmailIngestService;
use App\Services\Timeline\Sources\OutgoingEmailTimelineEventSource;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ServiceCaseEmailWorkspacePhase1Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'inbound_email.enabled' => true,
            'inbound_email.reply.enabled' => true,
            'inbound_email.reply.mailboxes' => ['support@radiumbox.com'],
            'inbound_email.mailboxes' => [
                'support@radiumbox.com' => 'support',
            ],
            'inbound_email.gmail.api_base_url' => 'https://gmail.googleapis.com',
            'inbound_email.gmail.http_retry_times' => 1,
            'cashfree.system_user_email' => 'superadmin@radium.local',
            'service_case_assignment.automation_grace_period_enabled' => false,
            'service_case_assignment.round_robin_enabled' => false,
        ]);

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        User::factory()->create([
            'name' => 'System',
            'email' => 'superadmin@radium.local',
        ])->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $this->mock(GmailAccessTokenService::class, function ($mock): void {
            $mock->shouldReceive('tokenForMailbox')->andReturn('test-access-token');
        });
    }

    public function test_email_thread_returns_empty_conversation_for_case_without_mail(): void
    {
        [$order, $incident] = $this->seedCustomerWithOpenIncident('empty-thread@example.com');
        $admin = $this->createAdmin();

        $response = $this->actingAs($admin)->getJson(
            route('dashboard.service-cases.email-thread', $incident),
        );

        $response->assertOk();
        $response->assertJsonPath('messages', []);
        $response->assertJsonPath('can_reply', false);
        $response->assertJsonPath('reply_to_incoming_email_message_id', null);
        $this->assertSame($order->id, $incident->order_id);
    }

    public function test_email_thread_returns_inbound_and_outbound_in_order(): void
    {
        [$order, $incident, $message, $admin] = $this->seedLinkedEmailAndAdmin();

        OutgoingEmailMessage::query()->create([
            'in_reply_to_incoming_email_message_id' => $message->id,
            'incident_id' => $incident->id,
            'order_id' => $order->id,
            'mailbox' => 'support@radiumbox.com',
            'to_email' => 'customer@example.com',
            'subject' => 'Re: Support request',
            'body_html' => '<p>We are on it.</p>',
            'body_text' => 'We are on it.',
            'preview' => 'We are on it.',
            'thread_id' => 'thr-workspace-1',
            'provider' => 'gmail',
            'provider_message_id' => 'gmail-out-existing',
            'sent_by_user_id' => $admin->id,
            'sent_at' => now()->addMinute(),
            'status' => OutgoingEmailMessageStatus::Sent,
        ]);

        $response = $this->actingAs($admin)->getJson(
            route('dashboard.service-cases.email-thread', $incident),
        );

        $response->assertOk();
        $messages = $response->json('messages');
        $this->assertCount(2, $messages);
        $this->assertSame('inbound', $messages[0]['direction']);
        $this->assertSame('outbound', $messages[1]['direction']);
        $this->assertTrue($response->json('can_reply'));
        $this->assertSame($message->id, $response->json('reply_to_incoming_email_message_id'));
    }

    public function test_admin_can_send_reply_from_workspace_path(): void
    {
        [, $incident, $message, $admin] = $this->seedLinkedEmailAndAdmin();

        Http::fake([
            'https://gmail.googleapis.com/gmail/v1/users/me/messages/send' => Http::response([
                'id' => 'gmail-workspace-admin',
                'threadId' => 'thr-workspace-1',
            ], 200),
        ]);

        $thread = $this->actingAs($admin)->getJson(
            route('dashboard.service-cases.email-thread', $incident),
        );
        $thread->assertOk();
        $this->assertTrue($thread->json('can_reply'));

        $response = $this->actingAs($admin)->postJson(
            route('dashboard.incoming-email-messages.reply', $message),
            [
                'subject' => 'Re: Support request',
                'body_html' => '<p>Admin workspace reply.</p>',
                'template_key' => 'blank',
            ],
        );

        $response->assertOk();
        $outgoing = OutgoingEmailMessage::query()->latest('id')->first();
        $this->assertNotNull($outgoing);
        $this->assertSame(OutgoingEmailMessageStatus::Sent, $outgoing->status);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'outgoing_email.sent',
            'auditable_id' => $outgoing->id,
        ]);
    }

    public function test_super_admin_can_reply_via_workspace_gate(): void
    {
        [, , $message] = $this->seedLinkedEmailAndAdmin();

        $superAdmin = User::factory()->create(['is_active' => true]);
        $superAdmin->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        Http::fake([
            'https://gmail.googleapis.com/gmail/v1/users/me/messages/send' => Http::response([
                'id' => 'gmail-workspace-super',
                'threadId' => 'thr-workspace-1',
            ], 200),
        ]);

        $response = $this->actingAs($superAdmin)->postJson(
            route('dashboard.incoming-email-messages.reply', $message),
            [
                'subject' => 'Re: Support request',
                'body_html' => '<p>Super admin workspace reply.</p>',
                'template_key' => 'blank',
            ],
        );

        $response->assertOk();
    }

    public function test_assigned_owner_can_reply_without_email_reply_permission(): void
    {
        [$order, $incident, $message] = $this->seedLinkedEmailAndAdmin();

        $assignee = User::factory()->create(['is_active' => true]);
        $assignee->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $this->assertFalse($assignee->can('email.reply'));
        $incident->update(['assigned_to_user_id' => $assignee->id]);

        Http::fake([
            'https://gmail.googleapis.com/gmail/v1/users/me/messages/send' => Http::response([
                'id' => 'gmail-workspace-assignee',
                'threadId' => 'thr-workspace-1',
            ], 200),
        ]);

        $thread = $this->actingAs($assignee)->getJson(
            route('dashboard.service-cases.email-thread', $incident->fresh()),
        );
        $thread->assertOk();
        $this->assertTrue($thread->json('can_reply'));

        $response = $this->actingAs($assignee)->postJson(
            route('dashboard.incoming-email-messages.reply', $message->fresh()),
            [
                'subject' => 'Re: Support request',
                'body_html' => '<p>Assignee workspace reply.</p>',
                'template_key' => 'blank',
            ],
        );

        $response->assertOk();

        $outgoing = OutgoingEmailMessage::query()->first();
        $this->assertNotNull($outgoing);

        $timeline = app(OutgoingEmailTimelineEventSource::class, ['order' => $order])->collect();
        $this->assertTrue(
            $timeline->contains(
                fn ($event): bool => $event->type === TimelineEventType::OutgoingEmail
                    && $event->dedupeKey === 'outgoing_email:'.$outgoing->id,
            ),
        );
    }

    public function test_other_agent_cannot_reply_and_thread_reports_cannot_reply(): void
    {
        [, $incident, $message] = $this->seedLinkedEmailAndAdmin();

        $owner = User::factory()->create(['is_active' => true]);
        $owner->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $other = User::factory()->create(['is_active' => true]);
        $other->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $incident->update(['assigned_to_user_id' => $owner->id]);

        $thread = $this->actingAs($other)->getJson(
            route('dashboard.service-cases.email-thread', $incident->fresh()),
        );
        $thread->assertOk();
        $this->assertFalse($thread->json('can_reply'));

        $response = $this->actingAs($other)->postJson(
            route('dashboard.incoming-email-messages.reply', $message->fresh()),
            [
                'subject' => 'Re: Support request',
                'body_html' => '<p>Should fail.</p>',
            ],
        );

        $response->assertForbidden();
        $this->assertSame(0, OutgoingEmailMessage::query()->count());
    }

    public function test_unassigned_agent_cannot_reply(): void
    {
        [, $incident, $message] = $this->seedLinkedEmailAndAdmin();
        $this->assertNull($incident->assigned_to_user_id);

        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $thread = $this->actingAs($agent)->getJson(
            route('dashboard.service-cases.email-thread', $incident),
        );
        $thread->assertOk();
        $this->assertFalse($thread->json('can_reply'));

        $response = $this->actingAs($agent)->postJson(
            route('dashboard.incoming-email-messages.reply', $message),
            [
                'subject' => 'Re: Support request',
                'body_html' => '<p>Unassigned.</p>',
            ],
        );

        $response->assertForbidden();
    }

    /**
     * @return array{0: Order, 1: Incident, 2: IncomingEmailMessage, 3: User}
     */
    private function seedLinkedEmailAndAdmin(): array
    {
        [$order, $incident] = $this->seedCustomerWithOpenIncident('customer@example.com');

        $message = app(IncomingEmailIngestService::class)->ingest(new NormalizedInboundEmail(
            mailbox: 'support@radiumbox.com',
            provider: 'fixture',
            providerMessageId: 'gmail-workspace-in-1',
            rfcMessageId: '<workspace-1@radium.test>',
            threadId: 'thr-workspace-1',
            fromEmail: 'customer@example.com',
            fromName: 'Customer',
            toEmails: ['support@radiumbox.com'],
            subject: 'Support request',
            preview: 'Need help',
            receivedAt: now(),
            attachmentCount: 0,
            headers: [],
            labels: [],
            rawPayload: [],
        ));

        $this->assertSame(IncomingEmailMessageStatus::Linked, $message?->status);

        $admin = $this->createAdmin();

        return [$order, $incident, $message->fresh(), $admin];
    }

    /**
     * @return array{0: Order, 1: Incident}
     */
    private function seedCustomerWithOpenIncident(string $email): array
    {
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-EMAIL-WS-'.uniqid(),
            'serial_number' => 'SN-EMAIL-WS-'.uniqid(),
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Workspace Customer',
            'customer_phone' => '9876508888',
            'customer_email' => $email,
            'status' => 'active',
            'created_by' => $creator->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-EMAIL-WS-'.uniqid(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Open support case',
            'description' => 'Open incident for email workspace tests.',
            'status' => IncidentStatus::Open,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
        ]);

        return [$order, $incident];
    }

    private function createAdmin(): User
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'availability_status' => TeamAvailabilityStatus::Available,
            'availability_updated_at' => now(),
        ]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $admin;
    }
}
