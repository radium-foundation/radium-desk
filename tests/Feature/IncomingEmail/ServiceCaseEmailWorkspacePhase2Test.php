<?php

namespace Tests\Feature\IncomingEmail;

use App\Data\IncomingEmail\NormalizedInboundEmail;
use App\Enums\IncomingEmailMessageStatus;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\OutgoingEmailMessageStatus;
use App\Models\Incident;
use App\Models\IncomingEmailMessage;
use App\Models\Order;
use App\Models\OutgoingEmailMessage;
use App\Models\User;
use App\Services\IncomingEmail\Gmail\GmailAccessTokenService;
use App\Services\IncomingEmail\IncomingEmailIngestService;
use App\Services\IncomingEmail\IncomingEmailWorkspaceReadState;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ServiceCaseEmailWorkspacePhase2Test extends TestCase
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
            'cashfree.system_user_email' => 'superadmin@radium.local',
            'service_case_assignment.automation_grace_period_enabled' => false,
            'service_case_assignment.round_robin_enabled' => false,
        ]);

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        User::factory()->create([
            'email' => 'superadmin@radium.local',
        ])->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $this->mock(GmailAccessTokenService::class, function ($mock): void {
            $mock->shouldReceive('tokenForMailbox')->andReturn('test-access-token');
        });

        Cache::flush();
    }

    public function test_thread_includes_header_timestamps_and_default_re_subject(): void
    {
        [$order, $incident, $message, $admin] = $this->seedLinkedEmail('phase2@example.com', 'Device offline');

        OutgoingEmailMessage::query()->create([
            'in_reply_to_incoming_email_message_id' => $message->id,
            'incident_id' => $incident->id,
            'order_id' => $order->id,
            'mailbox' => 'support@radiumbox.com',
            'to_email' => 'phase2@example.com',
            'subject' => 'Re: Device offline',
            'body_html' => '<p>Working on it</p>',
            'preview' => 'Working on it',
            'status' => OutgoingEmailMessageStatus::Sent,
            'sent_at' => now()->addMinute(),
            'sent_by_user_id' => $admin->id,
            'provider' => 'gmail',
        ]);

        $response = $this->actingAs($admin)->getJson(
            route('dashboard.service-cases.email-thread', $incident),
        );

        $response->assertOk();
        $this->assertNotNull($response->json('last_customer_email_at'));
        $this->assertNotNull($response->json('last_outgoing_email_at'));
        $this->assertSame('Re: Device offline', $response->json('default_subject'));
        $this->assertNotEmpty($response->json('customer_label'));
        $this->assertNotEmpty($response->json('owner_label'));
        $this->assertGreaterThanOrEqual(2, count($response->json('messages')));
    }

    public function test_unread_badge_clears_after_mark_read(): void
    {
        [, $incident, $message, $admin] = $this->seedLinkedEmail('unread@example.com');

        $before = $this->actingAs($admin)->getJson(
            route('dashboard.service-cases.email-thread', $incident),
        );
        $before->assertOk();
        $this->assertSame(1, $before->json('unread_inbound_count'));

        $mark = $this->actingAs($admin)->postJson(
            route('dashboard.service-cases.email-thread.read', $incident),
            ['latest_inbound_id' => $message->id],
        );
        $mark->assertOk();
        $this->assertSame(0, $mark->json('unread_inbound_count'));

        $after = $this->actingAs($admin)->getJson(
            route('dashboard.service-cases.email-thread', $incident),
        );
        $after->assertOk();
        $this->assertSame(0, $after->json('unread_inbound_count'));
    }

    public function test_pagination_returns_latest_page_and_older_cursor(): void
    {
        [$order, $incident, , $admin] = $this->seedLinkedEmail('page@example.com', 'Seed');

        for ($i = 1; $i <= 12; $i++) {
            IncomingEmailMessage::query()->create([
                'mailbox' => 'support@radiumbox.com',
                'channel' => 'support',
                'provider' => 'fixture',
                'provider_message_id' => 'page-'.$i,
                'rfc_message_id' => '<page-'.$i.'@radium.test>',
                'thread_id' => 'thr-page',
                'from_email' => 'page@example.com',
                'subject' => 'Message '.$i,
                'preview' => 'Body '.$i,
                'status' => IncomingEmailMessageStatus::Linked,
                'incident_id' => $incident->id,
                'order_id' => $order->id,
                'received_at' => Carbon::now()->subMinutes(20 - $i),
                'processed_at' => now(),
            ]);
        }

        $page = $this->actingAs($admin)->getJson(
            route('dashboard.service-cases.email-thread', [
                'incident' => $incident,
                'limit' => 5,
            ]),
        );

        $page->assertOk();
        $this->assertCount(5, $page->json('messages'));
        $this->assertTrue($page->json('has_more_older'));

        $oldest = $page->json('messages.0');
        $older = $this->actingAs($admin)->getJson(
            route('dashboard.service-cases.email-thread', [
                'incident' => $incident,
                'limit' => 5,
                'before_at' => $oldest['occurred_at'],
                'before_id' => $oldest['id'],
                'before_direction' => $oldest['direction'],
            ]),
        );

        $older->assertOk();
        $this->assertNotEmpty($older->json('messages'));
        $this->assertNotSame($oldest['id'], $older->json('messages.0.id'));
    }

    public function test_since_cursor_returns_only_newer_messages(): void
    {
        [$order, $incident, $first, $admin] = $this->seedLinkedEmail('since@example.com', 'First');

        $second = IncomingEmailMessage::query()->create([
            'mailbox' => 'support@radiumbox.com',
            'channel' => 'support',
            'provider' => 'fixture',
            'provider_message_id' => 'since-2',
            'rfc_message_id' => '<since-2@radium.test>',
            'thread_id' => 'thr-since',
            'from_email' => 'since@example.com',
            'subject' => 'Second',
            'preview' => 'Newer',
            'status' => IncomingEmailMessageStatus::Linked,
            'incident_id' => $incident->id,
            'order_id' => $order->id,
            'received_at' => now()->addMinute(),
            'processed_at' => now(),
        ]);

        $delta = $this->actingAs($admin)->getJson(
            route('dashboard.service-cases.email-thread', [
                'incident' => $incident,
                'since_at' => $first->received_at->toIso8601String(),
                'since_id' => $first->id,
                'since_direction' => 'inbound',
            ]),
        );

        $delta->assertOk();
        $this->assertTrue($delta->json('is_delta'));
        $ids = collect($delta->json('messages'))->pluck('id')->all();
        $this->assertContains($second->id, $ids);
        $this->assertNotContains($first->id, $ids);
    }

    public function test_large_thread_stays_bounded_to_limit(): void
    {
        [$order, $incident, , $admin] = $this->seedLinkedEmail('large@example.com');

        for ($i = 0; $i < 120; $i++) {
            IncomingEmailMessage::query()->create([
                'mailbox' => 'support@radiumbox.com',
                'channel' => 'support',
                'provider' => 'fixture',
                'provider_message_id' => 'large-'.$i,
                'rfc_message_id' => '<large-'.$i.'@radium.test>',
                'thread_id' => 'thr-large',
                'from_email' => 'large@example.com',
                'subject' => 'Large '.$i,
                'preview' => 'x',
                'status' => IncomingEmailMessageStatus::Linked,
                'incident_id' => $incident->id,
                'order_id' => $order->id,
                'received_at' => now()->subSeconds(120 - $i),
                'processed_at' => now(),
            ]);
        }

        $response = $this->actingAs($admin)->getJson(
            route('dashboard.service-cases.email-thread', [
                'incident' => $incident,
                'limit' => 50,
            ]),
        );

        $response->assertOk();
        $this->assertCount(50, $response->json('messages'));
        $this->assertTrue($response->json('has_more_older'));
    }

    public function test_read_state_service_is_idempotent(): void
    {
        [, $incident, $message, $admin] = $this->seedLinkedEmail('cursor@example.com');
        $state = app(IncomingEmailWorkspaceReadState::class);

        $state->markRead($admin, $incident, $message->id);
        $state->markRead($admin, $incident, $message->id);

        $this->assertSame(0, $state->unreadInboundCount($incident, $admin));
        $this->assertSame($message->id, $state->lastReadInboundId($admin, $incident));
    }

    /**
     * @return array{0: Order, 1: Incident, 2: IncomingEmailMessage, 3: User}
     */
    private function seedLinkedEmail(string $email, string $subject = 'Support request'): array
    {
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-P2-'.uniqid(),
            'serial_number' => 'SN-P2-'.uniqid(),
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Phase2 Customer',
            'customer_phone' => '9876507777',
            'customer_email' => $email,
            'status' => 'active',
            'created_by' => $creator->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-P2-'.uniqid(),
            'category' => 'General',
            'source' => IncidentSource::Email,
            'title' => 'Email workspace phase 2',
            'description' => 'Test case',
            'status' => IncidentStatus::Open,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
        ]);

        $message = app(IncomingEmailIngestService::class)->ingest(new NormalizedInboundEmail(
            mailbox: 'support@radiumbox.com',
            provider: 'fixture',
            providerMessageId: 'p2-'.uniqid(),
            rfcMessageId: '<p2-'.uniqid().'@radium.test>',
            threadId: 'thr-p2-'.uniqid(),
            fromEmail: $email,
            fromName: 'Customer',
            toEmails: ['support@radiumbox.com'],
            subject: $subject,
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
}
