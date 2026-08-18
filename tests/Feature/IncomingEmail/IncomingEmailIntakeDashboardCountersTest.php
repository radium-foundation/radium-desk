<?php

namespace Tests\Feature\IncomingEmail;

use App\Data\IncomingEmail\NormalizedInboundEmail;
use App\Enums\IncomingEmailClassification;
use App\Enums\IncomingEmailIntakeQueue;
use App\Enums\IncomingEmailMessageStatus;
use App\Models\IncomingEmailIgnoreStat;
use App\Models\IncomingEmailMessage;
use App\Models\User;
use App\Services\IncomingEmail\IncomingEmailIngestService;
use App\Services\IncomingEmail\IncomingEmailIntakeCounterService;
use App\Services\SettingService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncomingEmailIntakeDashboardCountersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        config([
            'inbound_email.enabled' => true,
            'inbound_email.auto_create_service_case' => false,
            'inbound_email.ignored_labels' => ['SPAM', 'CATEGORY_PROMOTIONS'],
            'inbound_email.system_sender_patterns' => ['mailer-daemon@'],
            'inbound_email.system_from_names' => ['mail delivery subsystem'],
            'inbound_email.auto_responder_header_tokens' => ['auto-submitted'],
            'inbound_email.mailboxes' => ['support@radiumbox.com' => 'support'],
            'inbound_email.preview_max_chars' => 280,
            'inbound_email.blocked_senders' => [],
            'inbound_email.blocked_domains' => [],
            'cashfree.system_user_email' => 'superadmin@radium.local',
        ]);

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        User::factory()->create([
            'name' => 'System',
            'email' => 'superadmin@radium.local',
        ])->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);
    }

    public function test_dashboard_shows_email_intake_kpi_card_when_needs_attention_exists(): void
    {
        $admin = $this->createAdmin('email-admin@test.com');

        IncomingEmailMessage::query()->create([
            'mailbox' => 'support@radiumbox.com',
            'provider' => 'fixture',
            'provider_message_id' => 'needs-review-1',
            'from_email' => 'unknown@example.com',
            'subject' => 'Help needed',
            'preview' => 'Please assist.',
            'status' => IncomingEmailMessageStatus::NeedsReview,
            'classification' => IncomingEmailClassification::UnknownCustomer,
            'received_at' => now(),
        ]);

        $html = (string) $this->actingAs($admin)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('data-email-intake-kpi', $html);
        $this->assertStringContainsString('Email Intake', $html);
        $this->assertStringContainsString('admin/incoming-emails?queue=needs_human', $html);
    }

    public function test_ignore_stat_counters_map_to_promotional_spam_and_automatic_queues(): void
    {
        $admin = $this->createAdmin('email-stats@test.com');
        $today = now()->toDateString();

        IncomingEmailIgnoreStat::query()->create([
            'stat_date' => $today,
            'reason' => 'promotions',
            'count' => 4,
        ]);
        IncomingEmailIgnoreStat::query()->create([
            'stat_date' => $today,
            'reason' => 'spam',
            'count' => 2,
        ]);
        IncomingEmailIgnoreStat::query()->create([
            'stat_date' => $today,
            'reason' => 'auto_responder',
            'count' => 3,
        ]);

        $counters = app(IncomingEmailIntakeCounterService::class)->visibleCounters($admin);
        $queues = collect($counters)->pluck('queue', 'queue');

        $this->assertArrayHasKey(IncomingEmailIntakeQueue::Promotional->value, $queues->all());
        $this->assertArrayHasKey(IncomingEmailIntakeQueue::Spam->value, $queues->all());
        $this->assertArrayHasKey(IncomingEmailIntakeQueue::Automatic->value, $queues->all());
        $this->assertSame(4, collect($counters)->firstWhere('queue', IncomingEmailIntakeQueue::Promotional->value)['count']);
    }

    public function test_user_without_email_intake_permission_does_not_see_widget(): void
    {
        $employee = User::factory()->create();
        $employee->assignRole(RolePermissionSeeder::ROLE_EMPLOYEE);

        IncomingEmailMessage::query()->create([
            'mailbox' => 'support@radiumbox.com',
            'provider' => 'fixture',
            'provider_message_id' => 'hidden-1',
            'from_email' => 'hidden@example.com',
            'subject' => 'Hidden',
            'preview' => 'Hidden',
            'status' => IncomingEmailMessageStatus::NeedsReview,
            'received_at' => now(),
        ]);

        $this->assertNull(app(IncomingEmailIntakeCounterService::class)->dashboardWidget($employee));

        $html = (string) $this->actingAs($employee)->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-email-intake-kpi', $html);
    }

    public function test_support_agent_with_email_intake_permission_sees_widget(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        IncomingEmailMessage::query()->create([
            'mailbox' => 'support@radiumbox.com',
            'provider' => 'fixture',
            'provider_message_id' => 'agent-visible-1',
            'from_email' => 'visible@example.com',
            'subject' => 'Visible',
            'preview' => 'Visible',
            'status' => IncomingEmailMessageStatus::NeedsReview,
            'received_at' => now(),
        ]);

        $this->assertNotNull(app(IncomingEmailIntakeCounterService::class)->dashboardWidget($agent));
    }

    public function test_admin_index_filters_needs_human_and_spam_queues(): void
    {
        $admin = $this->createAdmin('email-index@test.com');

        IncomingEmailMessage::query()->create([
            'mailbox' => 'support@radiumbox.com',
            'provider' => 'fixture',
            'provider_message_id' => 'human-1',
            'from_email' => 'human@example.com',
            'subject' => 'Needs review',
            'preview' => 'Help',
            'status' => IncomingEmailMessageStatus::NeedsReview,
            'received_at' => now(),
        ]);

        IncomingEmailMessage::query()->create([
            'mailbox' => 'support@radiumbox.com',
            'provider' => 'fixture',
            'provider_message_id' => 'spam-1',
            'from_email' => 'spam@example.com',
            'subject' => 'Spam offer',
            'preview' => 'Buy now',
            'status' => IncomingEmailMessageStatus::Ignored,
            'ignore_reason' => 'spam',
            'received_at' => now(),
        ]);

        $humanHtml = (string) $this->actingAs($admin)
            ->get(route('admin.incoming-emails.index', ['queue' => IncomingEmailIntakeQueue::NeedsHuman->value]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('IRA Learning Center', $humanHtml);
        $this->assertStringContainsString('human@example.com', $humanHtml);
        $this->assertStringContainsString('data-ira-row', $humanHtml);
        $this->assertStringNotContainsString('Spam offer', $humanHtml);
        $this->assertStringNotContainsString('needs_review', $humanHtml);
        $this->assertStringNotContainsString('unknown_customer', $humanHtml);

        $spamHtml = (string) $this->actingAs($admin)
            ->get(route('admin.incoming-emails.index', ['queue' => IncomingEmailIntakeQueue::Spam->value]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Spam offer', $spamHtml);
        $this->assertStringContainsString('data-ira-row', $spamHtml);
        $this->assertStringNotContainsString('human@example.com', $spamHtml);
    }

    public function test_ingested_spam_increments_ignore_stat_counter_without_persisting_admin_row(): void
    {
        app(IncomingEmailIngestService::class)->ingest(new NormalizedInboundEmail(
            mailbox: 'support@radiumbox.com',
            provider: 'fixture',
            providerMessageId: 'spam-ingest-1',
            rfcMessageId: '<spam-ingest-1@radium.test>',
            threadId: 'thread-spam-1',
            fromEmail: 'promo@example.com',
            fromName: 'Promo',
            toEmails: ['support@radiumbox.com'],
            subject: 'Promo',
            preview: 'Promo body',
            receivedAt: now(),
            attachmentCount: 0,
            headers: [],
            labels: ['SPAM'],
            rawPayload: ['fixture' => true],
        ));

        $this->assertSame(1, app(IncomingEmailIntakeCounterService::class)->counts()[IncomingEmailIntakeQueue::Spam->value]);
        $this->assertSame(0, IncomingEmailMessage::query()->count());
    }

    private function createAdmin(string $email): User
    {
        $admin = User::factory()->create(['email' => $email]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        app(SettingService::class)->setMany([
            'assignment.communication_intake_primary_user_id' => (string) $admin->id,
        ]);

        $this->assertTrue($admin->can(RolePermissionSeeder::PERMISSION_EMAIL_INTAKE_VIEW));
        $this->assertTrue($admin->can(RolePermissionSeeder::PERMISSION_EMAIL_INTAKE_MANAGE));

        return $admin;
    }
}
