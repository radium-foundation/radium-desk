<?php

namespace Tests\Feature\IncomingEmail;

use App\Data\IncomingEmail\NormalizedInboundEmail;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\TeamAvailabilityStatus;
use App\Models\Incident;
use App\Models\IncomingEmailMessage;
use App\Models\Order;
use App\Models\Remark;
use App\Models\User;
use App\Services\IncomingEmail\IncomingEmailIngestService;
use App\Services\Operations\PresenceEngineService;
use App\Services\SettingService;
use App\Services\Timeline\Customer360TimelineService;
use App\Services\Timeline\IncomingEmailReopenTimelinePresenter;
use App\Support\Remarks\RemarkSystemSource;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncomingEmailReopenTimelinePresentationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'inbound_email.enabled' => true,
            'inbound_email.auto_create_service_case' => false,
            'inbound_email.ignored_labels' => [
                'SPAM',
                'TRASH',
                'CATEGORY_PROMOTIONS',
                'CATEGORY_SOCIAL',
            ],
            'inbound_email.system_sender_patterns' => [
                'mailer-daemon@',
                'noreply@',
            ],
            'inbound_email.system_from_names' => [
                'mail delivery subsystem',
            ],
            'inbound_email.auto_responder_header_tokens' => [
                'auto-submitted',
                'list-unsubscribe',
            ],
            'inbound_email.mailboxes' => [
                'support@radiumbox.com' => 'support',
            ],
            'inbound_email.preview_max_chars' => 280,
            'inbound_email.blocked_senders' => [],
            'inbound_email.blocked_domains' => [],
            'cashfree.system_user_email' => 'superadmin@radium.local',
            'notifications.high_priority_enabled' => true,
            'ira.business_timeline.enabled' => true,
        ]);

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        User::factory()->create([
            'name' => 'System',
            'email' => 'superadmin@radium.local',
        ])->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $primary = $this->createAdminUser('tl-primary@test.com', 'Timeline Primary');
        $fallback = $this->createAdminUser('tl-fallback@test.com', 'Timeline Fallback');

        app(SettingService::class)->setMany([
            'assignment.communication_intake_primary_user_id' => (string) $primary->id,
            'assignment.communication_intake_fallback_user_id' => (string) $fallback->id,
            'notifications.high_priority_enabled' => '1',
        ]);
    }

    public function test_reopen_email_renders_as_single_unified_timeline_card(): void
    {
        $owner = $this->createAgent('tl-owner@test.com', 'Timeline Owner');
        [$order, $incident] = $this->seedClosedCase('timeline@example.com', $owner);

        $subject = 'Still broken after close';
        $preview = 'Device failed again yesterday.';
        $message = $this->ingestEmail(
            fromEmail: 'timeline@example.com',
            subject: $subject,
            preview: $preview,
        );

        $this->assertNotNull($message);

        $html = (string) $this->actingAs($owner)
            ->getJson(route('dashboard.service-cases.customer-360.timeline', $incident->fresh()).'?tab=1&offset=0')
            ->assertOk()
            ->json('html');

        $this->assertStringContainsString('data-timeline-unified-email-reopen', $html);
        $this->assertStringContainsString('Customer replied by email', $html);
        $this->assertStringContainsString(IncomingEmailReopenTimelinePresenter::REOPEN_BODY, $html);
        $this->assertStringContainsString('Case Reopened', $html);
        $this->assertStringContainsString($subject, $html);
        $this->assertStringContainsString('timeline@example.com', $html);
        $this->assertStringContainsString($preview, $html);
        $this->assertStringContainsString('Technical Details', $html);
        $this->assertStringContainsString('data-timeline-technical-details', $html);

        $this->assertStringNotContainsString('Internal note added', $html);
        $this->assertStringNotContainsString('Service case reopened by inbound email', $html);

        // Technical Details present and collapsed (no open attribute on the details element).
        $this->assertMatchesRegularExpression(
            '/<details[^>]*data-timeline-technical-details(?![^>]*\bopen\b)[^>]*>/i',
            $html,
        );
        $htmlWithoutTechnical = (string) preg_replace(
            '/<details[^>]*data-timeline-technical-details[\s\S]*?<\/details>/i',
            '',
            $html,
        );
        $this->assertStringNotContainsString((string) $message->rfc_message_id, $htmlWithoutTechnical);

        $viewModel = app(Customer360TimelineService::class)->businessForIncident($incident->fresh());
        $reopenItems = $viewModel->items()->filter(
            fn ($item) => $item->unifiedPresentation === true,
        );

        $this->assertCount(1, $reopenItems);
        $item = $reopenItems->first();
        $this->assertSame('Customer replied by email', $item->title);
        $this->assertSame(IncomingEmailReopenTimelinePresenter::REOPEN_BODY, $item->summary);
        $this->assertContains('Case Reopened', $item->actionBadges);
        $this->assertContains('Priority Raised', $item->actionBadges);
        $this->assertNotEmpty($item->displayFields);
        $this->assertNotEmpty($item->technicalFields);
        $this->assertSame('bi-envelope', $item->iconClass);

        $titles = $viewModel->items()->pluck('title')->all();
        $this->assertSame(1, collect($titles)->filter(fn (string $title) => $title === 'Customer replied by email')->count());
        $this->assertNotContains('Internal note added.', $titles);

        // Backend audit remark remains stored; only presentation hides it.
        $this->assertTrue(
            Remark::query()
                ->where('remarkable_type', $incident->getMorphClass())
                ->where('remarkable_id', $incident->id)
                ->get()
                ->contains(function (Remark $remark): bool {
                    return $remark->metadataDto()->systemSource === RemarkSystemSource::REOPEN
                        && str_contains(strtolower($remark->body), 'service case reopened by inbound email');
                }),
        );
    }

    /**
     * @return array{0: Order, 1: Incident}
     */
    private function seedClosedCase(string $email, User $owner): array
    {
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-TL-'.uniqid(),
            'serial_number' => 'SN-TL-'.uniqid(),
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Timeline Customer',
            'customer_phone' => '9876508888',
            'customer_email' => $email,
            'status' => 'active',
            'created_by' => $creator->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-TL-'.uniqid(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Closed case for timeline polish',
            'description' => 'Closed before inbound email.',
            'status' => IncidentStatus::Closed,
            'high_priority' => false,
            'assigned_to_user_id' => $owner->id,
            'created_by' => $owner->id,
            'updated_by' => $owner->id,
        ]);

        return [$order, $incident];
    }

    private function ingestEmail(
        string $fromEmail,
        string $subject,
        string $preview,
    ): ?IncomingEmailMessage {
        $dto = new NormalizedInboundEmail(
            mailbox: 'support@radiumbox.com',
            provider: 'fixture',
            providerMessageId: 'prov-'.uniqid(),
            rfcMessageId: '<'.uniqid('rfc-', true).'@radium.test>',
            threadId: 'thread-'.uniqid(),
            fromEmail: $fromEmail,
            fromName: 'Customer',
            toEmails: ['support@radiumbox.com'],
            subject: $subject,
            preview: $preview,
            receivedAt: now(),
            attachmentCount: 0,
            headers: [],
            labels: [],
            rawPayload: ['fixture' => true],
        );

        return app(IncomingEmailIngestService::class)->ingest($dto);
    }

    private function createAgent(string $email, string $name): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => $email,
            'is_active' => true,
            'availability_status' => TeamAvailabilityStatus::Available,
            'availability_updated_at' => now(),
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        return $user;
    }

    private function createAdminUser(string $email, string $name): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => $email,
            'is_active' => true,
            'availability_status' => TeamAvailabilityStatus::Available,
            'availability_updated_at' => now(),
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        app(PresenceEngineService::class)->startSession($user);

        return $user;
    }
}
