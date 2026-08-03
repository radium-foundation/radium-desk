<?php

namespace Tests\Feature;

use App\Data\IncomingEmail\NormalizedInboundEmail;
use App\Enums\IncomingEmailClassification;
use App\Enums\IncomingEmailMessageStatus;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\OutgoingEmailMessageStatus;
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

class OutgoingEmailReplyTest extends TestCase
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
        ]);

        $this->mock(GmailAccessTokenService::class, function ($mock): void {
            $mock->shouldReceive('tokenForMailbox')->andReturn('test-access-token');
        });
    }

    public function test_admin_can_send_manual_reply_with_thread_continuity(): void
    {
        [$order, $incident, $message, $admin] = $this->seedLinkedEmailAndAdmin();

        Http::fake([
            'https://gmail.googleapis.com/gmail/v1/users/me/messages/send' => Http::response([
                'id' => 'gmail-out-1',
                'threadId' => 'thr-reply-1',
                'labelIds' => ['SENT'],
            ], 200),
        ]);

        $response = $this->actingAs($admin)->postJson(
            route('dashboard.incoming-email-messages.reply', $message),
            [
                'subject' => 'Re: Support request',
                'body_html' => '<p>Thanks for writing in. We are looking into this.</p>',
                'template_key' => 'blank',
            ],
        );

        $response->assertOk();
        $response->assertJsonPath('outgoing_email_message.provider_message_id', 'gmail-out-1');

        $outgoing = OutgoingEmailMessage::query()->first();
        $this->assertNotNull($outgoing);
        $this->assertSame(OutgoingEmailMessageStatus::Sent, $outgoing->status);
        $this->assertSame('thr-reply-1', $outgoing->thread_id);
        $this->assertSame($message->id, $outgoing->in_reply_to_incoming_email_message_id);
        $this->assertSame($incident->id, $outgoing->incident_id);
        $this->assertSame($order->id, $outgoing->order_id);

        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), '/messages/send')) {
                return false;
            }

            $raw = (string) ($request['raw'] ?? '');
            $decoded = base64_decode(strtr($raw, '-_', '+/'), true);

            return is_string($decoded)
                && str_contains($decoded, 'In-Reply-To:')
                && ($request['threadId'] ?? null) === 'thr-reply-1';
        });

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'outgoing_email.sent',
            'auditable_id' => $outgoing->id,
        ]);

        $timeline = app(OutgoingEmailTimelineEventSource::class, ['order' => $order])->collect();
        $this->assertTrue(
            $timeline->contains(
                fn ($event): bool => $event->type === TimelineEventType::OutgoingEmail
                    && $event->dedupeKey === 'outgoing_email:'.$outgoing->id,
            ),
        );
    }

    public function test_template_preview_and_template_reply(): void
    {
        [$order, $incident, $message, $admin] = $this->seedLinkedEmailAndAdmin();

        $preview = $this->actingAs($admin)->postJson(
            route('dashboard.incoming-email-messages.reply-preview', $message),
            ['template_key' => 'service_case_closed'],
        );

        $preview->assertOk();
        $preview->assertJsonPath('template_key', 'service_case_closed');
        $this->assertNotSame('', (string) $preview->json('subject'));
        $this->assertStringContainsString('<', (string) $preview->json('body_html'));

        Http::fake([
            'https://gmail.googleapis.com/gmail/v1/users/me/messages/send' => Http::response([
                'id' => 'gmail-out-template',
                'threadId' => 'thr-reply-1',
            ], 200),
        ]);

        $send = $this->actingAs($admin)->postJson(
            route('dashboard.incoming-email-messages.reply', $message),
            [
                'subject' => (string) $preview->json('subject'),
                'body_html' => '<p>Edited template body</p>',
                'template_key' => 'service_case_closed',
            ],
        );

        $send->assertOk();
        $this->assertDatabaseHas('outgoing_email_messages', [
            'template_key' => 'service_case_closed',
            'provider_message_id' => 'gmail-out-template',
            'status' => OutgoingEmailMessageStatus::Sent->value,
        ]);
        $this->assertSame($order->id, OutgoingEmailMessage::query()->first()?->order_id);
        $this->assertSame($incident->id, OutgoingEmailMessage::query()->first()?->incident_id);
    }

    public function test_agent_without_permission_cannot_reply(): void
    {
        [, , $message] = $this->seedLinkedEmailAndAdmin();
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $response = $this->actingAs($agent)->postJson(
            route('dashboard.incoming-email-messages.reply', $message),
            [
                'subject' => 'Re: Support request',
                'body_html' => '<p>Hello</p>',
            ],
        );

        $response->assertForbidden();
        $this->assertSame(0, OutgoingEmailMessage::query()->count());
    }

    public function test_reply_disabled_by_feature_flag(): void
    {
        config(['inbound_email.reply.enabled' => false]);

        [, , $message, $admin] = $this->seedLinkedEmailAndAdmin();

        $response = $this->actingAs($admin)->postJson(
            route('dashboard.incoming-email-messages.reply', $message),
            [
                'subject' => 'Re: Support request',
                'body_html' => '<p>Hello</p>',
            ],
        );

        $response->assertForbidden();
    }

    public function test_reply_blocked_for_non_enabled_mailbox(): void
    {
        [, , $message, $admin] = $this->seedLinkedEmailAndAdmin();
        $message->update(['mailbox' => 'refund@radiumbox.com']);

        $response = $this->actingAs($admin)->postJson(
            route('dashboard.incoming-email-messages.reply', $message),
            [
                'subject' => 'Re: Support request',
                'body_html' => '<p>Hello</p>',
            ],
        );

        $response->assertForbidden();
    }

    public function test_own_outbound_echo_is_ignored_and_not_linked(): void
    {
        [$order, $incident] = $this->seedCustomerWithOpenIncident('customer@example.com');

        OutgoingEmailMessage::query()->create([
            'incident_id' => $incident->id,
            'order_id' => $order->id,
            'mailbox' => 'support@radiumbox.com',
            'to_email' => 'customer@example.com',
            'subject' => 'Re: Support request',
            'body_html' => '<p>Sent</p>',
            'thread_id' => 'thr-echo',
            'provider' => 'gmail',
            'provider_message_id' => 'gmail-echo-1',
            'status' => OutgoingEmailMessageStatus::Sent,
            'sent_at' => now(),
        ]);

        $message = app(IncomingEmailIngestService::class)->ingest(new NormalizedInboundEmail(
            mailbox: 'support@radiumbox.com',
            provider: 'gmail',
            providerMessageId: 'gmail-echo-1',
            rfcMessageId: '<echo@radium.test>',
            threadId: 'thr-echo',
            fromEmail: 'support@radiumbox.com',
            fromName: 'Support',
            toEmails: ['customer@example.com'],
            subject: 'Re: Support request',
            preview: 'Sent',
            receivedAt: now(),
            attachmentCount: 0,
            headers: [],
            labels: [],
            rawPayload: [],
        ));

        $this->assertSame(IncomingEmailMessageStatus::Ignored, $message?->status);
        $this->assertSame(IncomingEmailClassification::OwnOutbound, $message?->classification);
        $this->assertNull($message?->incident_id);
    }

    /**
     * @return array{0: Order, 1: Incident, 2: IncomingEmailMessage, 3: User}
     */
    private function seedLinkedEmailAndAdmin(): array
    {
        [$order, $incident] = $this->seedCustomerWithOpenIncident('customer@example.com');

        $message = app(IncomingEmailIngestService::class)->ingest(new NormalizedInboundEmail(
            mailbox: 'support@radiumbox.com',
            provider: 'gmail',
            providerMessageId: 'gmail-in-1',
            rfcMessageId: '<inbound-1@radium.test>',
            threadId: 'thr-reply-1',
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

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

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
            'order_id' => 'RD-REPLY-'.uniqid(),
            'serial_number' => 'SN-REPLY-'.uniqid(),
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Reply Customer',
            'customer_phone' => '9876509999',
            'customer_email' => $email,
            'status' => 'active',
            'created_by' => $creator->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-REPLY-'.uniqid(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Open support case',
            'description' => 'Open incident for reply tests.',
            'status' => IncidentStatus::Open,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
        ]);

        return [$order, $incident];
    }
}
