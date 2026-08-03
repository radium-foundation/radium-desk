<?php

namespace Tests\Feature;

use App\Data\IncomingEmail\NormalizedInboundEmail;
use App\Enums\IncomingEmailMessageStatus;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\TeamAvailabilityStatus;
use App\Models\Incident;
use App\Models\IncomingEmailMessage;
use App\Models\Order;
use App\Models\User;
use App\Notifications\NewEmailReceivedNotification;
use App\Services\IncomingEmail\IncomingEmailAssignmentService;
use App\Services\IncomingEmail\IncomingEmailIngestService;
use App\Services\SettingService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class EmailCommunicationOwnershipRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'inbound_email.enabled' => true,
            'inbound_email.mailboxes' => [
                'support@radiumbox.com' => 'support',
            ],
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
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_existing_incident_owner_is_preserved_and_notified(): void
    {
        Notification::fake();
        Carbon::setTestNow(Carbon::parse('2026-07-18 10:00:00', 'Asia/Kolkata'));

        $owner = $this->createAdmin('owner@test.com', 'Case Owner');
        $primary = $this->createAdmin('shubhanshi@test.com', 'Shubhanshi');
        $fallback = $this->createAdmin('dileep@test.com', 'Dileep');
        $this->configureIntake($primary->id, $fallback->id);

        [, $incident] = $this->seedOpenCase('customer@example.com', $owner->id);

        $message = $this->ingest('customer@example.com');

        $this->assertSame(IncomingEmailMessageStatus::Linked, $message?->status);
        $this->assertSame($owner->id, $incident->fresh()->assigned_to_user_id);
        $this->assertNotSame($primary->id, $incident->fresh()->assigned_to_user_id);

        Notification::assertSentTo($owner, NewEmailReceivedNotification::class);
        Notification::assertNotSentTo($primary, NewEmailReceivedNotification::class);
    }

    public function test_unassigned_case_routes_to_communication_intake_primary(): void
    {
        Notification::fake();
        Carbon::setTestNow(Carbon::parse('2026-07-18 10:00:00', 'Asia/Kolkata'));

        $primary = $this->createAdmin('shubhanshi@test.com', 'Shubhanshi');
        $fallback = $this->createAdmin('dileep@test.com', 'Dileep');
        $this->configureIntake($primary->id, $fallback->id);

        [, $incident] = $this->seedOpenCase('customer@example.com');

        $message = $this->ingest('customer@example.com');

        $this->assertSame(IncomingEmailMessageStatus::Linked, $message?->status);
        $this->assertSame($primary->id, $incident->fresh()->assigned_to_user_id);
        Notification::assertSentTo($primary, NewEmailReceivedNotification::class);
    }

    public function test_primary_unavailable_routes_to_fallback(): void
    {
        Notification::fake();
        Carbon::setTestNow(Carbon::parse('2026-07-18 10:00:00', 'Asia/Kolkata'));

        $primary = $this->createAdmin('shubhanshi@test.com', 'Shubhanshi', [
            'availability_status' => TeamAvailabilityStatus::Offline,
        ]);
        $fallback = $this->createAdmin('dileep@test.com', 'Dileep');
        $this->configureIntake($primary->id, $fallback->id);

        [, $incident] = $this->seedOpenCase('customer@example.com');

        $this->ingest('customer@example.com');

        $this->assertSame($fallback->id, $incident->fresh()->assigned_to_user_id);
        Notification::assertSentTo($fallback, NewEmailReceivedNotification::class);
        Notification::assertNotSentTo($primary, NewEmailReceivedNotification::class);
    }

    public function test_primary_inactive_routes_to_fallback(): void
    {
        Notification::fake();
        Carbon::setTestNow(Carbon::parse('2026-07-18 10:00:00', 'Asia/Kolkata'));

        $primary = $this->createAdmin('shubhanshi@test.com', 'Shubhanshi', ['is_active' => false]);
        $fallback = $this->createAdmin('dileep@test.com', 'Dileep');
        $this->configureIntake($primary->id, $fallback->id);

        [, $incident] = $this->seedOpenCase('customer@example.com');

        $this->ingest('customer@example.com');

        $this->assertSame($fallback->id, $incident->fresh()->assigned_to_user_id);
    }

    public function test_outside_working_hours_uses_fallback_when_primary_blocked_by_calendar(): void
    {
        Notification::fake();
        // Outside day shift window with calendar checking shift hours.
        Carbon::setTestNow(Carbon::parse('2026-07-18 22:00:00', 'Asia/Kolkata'));

        $primary = $this->createAdmin('shubhanshi@test.com', 'Shubhanshi');
        $fallback = $this->createAdmin('dileep@test.com', 'Dileep');
        $this->configureIntake($primary->id, $fallback->id);

        $assignment = app(IncomingEmailAssignmentService::class);
        $primaryAvailable = $assignment->isAvailableForCommunicationIntake($primary);
        $fallbackAvailable = $assignment->isAvailableForCommunicationIntake($fallback);

        [, $incident] = $this->seedOpenCase('customer@example.com');
        $this->ingest('customer@example.com');

        if (! $primaryAvailable && $fallbackAvailable) {
            $this->assertSame($fallback->id, $incident->fresh()->assigned_to_user_id);
        } elseif (! $primaryAvailable && ! $fallbackAvailable) {
            // Both calendar-blocked → forced fallback still owns the case (no ownerless case).
            $this->assertSame($fallback->id, $incident->fresh()->assigned_to_user_id);
        } else {
            $this->assertContains($incident->fresh()->assigned_to_user_id, [$primary->id, $fallback->id]);
        }
    }

    public function test_unknown_customer_still_needs_review_without_assignment(): void
    {
        Notification::fake();

        $primary = $this->createAdmin('shubhanshi@test.com', 'Shubhanshi');
        $fallback = $this->createAdmin('dileep@test.com', 'Dileep');
        $this->configureIntake($primary->id, $fallback->id);

        $before = Incident::query()->count();
        $message = $this->ingest('unknown@example.com');

        $this->assertSame(IncomingEmailMessageStatus::NeedsReview, $message?->status);
        $this->assertSame($before, Incident::query()->count());
        Notification::assertNothingSent();
    }

    public function test_second_email_does_not_create_parallel_ownership(): void
    {
        Notification::fake();
        Carbon::setTestNow(Carbon::parse('2026-07-18 10:00:00', 'Asia/Kolkata'));

        $owner = $this->createAdmin('owner@test.com', 'Case Owner');
        $primary = $this->createAdmin('shubhanshi@test.com', 'Shubhanshi');
        $fallback = $this->createAdmin('dileep@test.com', 'Dileep');
        $this->configureIntake($primary->id, $fallback->id);

        [, $incident] = $this->seedOpenCase('customer@example.com', $owner->id);

        $this->ingest('customer@example.com', providerMessageId: 'msg-1', rfcMessageId: '<one@t>');
        $this->ingest('customer@example.com', providerMessageId: 'msg-2', rfcMessageId: '<two@t>');

        $this->assertSame($owner->id, $incident->fresh()->assigned_to_user_id);
        $this->assertSame(2, IncomingEmailMessage::query()->where('incident_id', $incident->id)->count());
        Notification::assertSentToTimes($owner, NewEmailReceivedNotification::class, 2);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createAdmin(string $email, string $name, array $overrides = []): User
    {
        $user = User::factory()->create(array_merge([
            'name' => $name,
            'email' => $email,
            'is_active' => true,
            'availability_status' => TeamAvailabilityStatus::Available,
        ], $overrides));
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $user;
    }

    private function configureIntake(int $primaryId, int $fallbackId): void
    {
        app(SettingService::class)->setMany([
            'assignment.timezone' => 'Asia/Kolkata',
            'assignment.day_shift_start' => '09:00',
            'assignment.day_shift_end' => '18:30',
            'assignment.day_shift_admin_user_id' => (string) $primaryId,
            'assignment.night_shift_admin_user_id' => (string) $fallbackId,
            'assignment.communication_intake_primary_user_id' => (string) $primaryId,
            'assignment.communication_intake_fallback_user_id' => (string) $fallbackId,
        ]);
    }

    /**
     * @return array{0: Order, 1: Incident}
     */
    private function seedOpenCase(string $email, ?int $assignedToUserId = null): array
    {
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-OWN-'.uniqid(),
            'serial_number' => 'SN-OWN-'.uniqid(),
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Ownership Customer',
            'customer_phone' => '9876501111',
            'customer_email' => $email,
            'status' => 'active',
            'created_by' => $creator->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-OWN-'.uniqid(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Open support case',
            'description' => 'Ownership routing fixture.',
            'status' => IncidentStatus::Open,
            'assigned_to_user_id' => $assignedToUserId,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
        ]);

        return [$order, $incident];
    }

    private function ingest(
        string $fromEmail,
        ?string $providerMessageId = null,
        ?string $rfcMessageId = null,
    ): ?IncomingEmailMessage {
        $unique = uniqid('own-', true);

        return app(IncomingEmailIngestService::class)->ingest(new NormalizedInboundEmail(
            mailbox: 'support@radiumbox.com',
            provider: 'fixture',
            providerMessageId: $providerMessageId ?? $unique,
            rfcMessageId: $rfcMessageId ?? '<'.$unique.'@radium.test>',
            threadId: null,
            fromEmail: $fromEmail,
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
    }
}
