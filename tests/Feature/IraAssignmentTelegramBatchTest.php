<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\IraNotificationStatus;
use App\Enums\IraNotificationType;
use App\Enums\SupportAppointmentTimeSlot;
use App\Events\Operations\SupportAppointmentSmartAssigned;
use App\Models\Incident;
use App\Models\IraNotification;
use App\Models\Order;
use App\Models\SupportAppointment;
use App\Models\User;
use App\Services\Operations\IraAssignmentTelegramBatchService;
use App\Services\ServiceCaseAssignmentService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IraAssignmentTelegramBatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        config([
            'services.telegram.bot_token' => 'test-bot-token',
            'ira.communication.cooldown_minutes' => 60,
            'ira.communication.assignment_telegram_batch.enabled' => true,
            'ira.communication.assignment_telegram_batch.delay_minutes' => 5,
        ]);

        $this->enableTelegramNotifications();

        Carbon::setTestNow(Carbon::parse('2026-07-09 10:00:00', 'Asia/Kolkata'));
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Cache::flush();

        parent::tearDown();
    }

    public function test_single_ira_assignment_is_delayed_then_flushed_as_summary(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 1],
            ], 200),
        ]);

        $assignee = $this->createEngineerWithTelegram('Batch Agent', '100200300');
        [$incident, $appointment] = $this->createCase($assignee, 'RD-BATCH-1', 'SC13834', 'Installation');

        $this->fireSmartAssigned($incident, $appointment, $assignee);

        Http::assertNothingSent();
        $batch = app(IraAssignmentTelegramBatchService::class)->peek($assignee->id);
        $this->assertNotNull($batch);
        $this->assertSame($assignee->id, $batch['user_id']);
        $this->assertCount(1, $batch['items']);

        Carbon::setTestNow(Carbon::parse('2026-07-09 10:05:00', 'Asia/Kolkata'));
        $this->assertSame(1, app(IraAssignmentTelegramBatchService::class)->flushDue());

        $notification = IraNotification::query()
            ->where('user_id', $assignee->id)
            ->where('notification_type', IraNotificationType::IraAssignmentBatch->value)
            ->first();

        $this->assertNotNull($notification);
        $this->assertSame(IraNotificationStatus::Sent, $notification->status);
        $this->assertStringContainsString('🤖 IRA assigned new support cases', $notification->message);
        $this->assertStringContainsString('• SC13834 – Installation', $notification->message);
        $this->assertStringContainsString('Open Radium Desk to view your updated queue.', $notification->message);
        $this->assertNull(app(IraAssignmentTelegramBatchService::class)->peek($assignee->id));
        Http::assertSentCount(1);
    }

    public function test_multiple_ira_assignments_within_delay_window_aggregate_to_one_message(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 2],
            ], 200),
        ]);

        $assignee = $this->createEngineerWithTelegram('Multi Agent', '200300400');
        [$incidentA, $appointmentA] = $this->createCase($assignee, 'RD-BATCH-A', 'SC13834', 'Installation');
        [$incidentB, $appointmentB] = $this->createCase($assignee, 'RD-BATCH-B', 'SC13841', 'Demo');
        [$incidentC, $appointmentC] = $this->createCase($assignee, 'RD-BATCH-C', 'SC13852', 'Service');

        $this->fireSmartAssigned($incidentA, $appointmentA, $assignee);
        Carbon::setTestNow(Carbon::parse('2026-07-09 10:01:00', 'Asia/Kolkata'));
        $this->fireSmartAssigned($incidentB, $appointmentB, $assignee);
        Carbon::setTestNow(Carbon::parse('2026-07-09 10:02:00', 'Asia/Kolkata'));
        $this->fireSmartAssigned($incidentC, $appointmentC, $assignee);

        $batch = app(IraAssignmentTelegramBatchService::class)->peek($assignee->id);
        $this->assertNotNull($batch);
        $this->assertCount(3, $batch['items']);
        Http::assertNothingSent();

        Carbon::setTestNow(Carbon::parse('2026-07-09 10:05:00', 'Asia/Kolkata'));
        $this->artisan('ira:flush-assignment-telegram-batches')->assertSuccessful();

        $notification = IraNotification::query()
            ->where('user_id', $assignee->id)
            ->where('notification_type', IraNotificationType::IraAssignmentBatch->value)
            ->first();

        $this->assertNotNull($notification);
        $this->assertStringContainsString('• SC13834 – Installation', $notification->message);
        $this->assertStringContainsString('• SC13841 – Demo', $notification->message);
        $this->assertStringContainsString('• SC13852 – Service', $notification->message);
        $this->assertSame(
            1,
            IraNotification::query()
                ->where('user_id', $assignee->id)
                ->where('notification_type', IraNotificationType::IraAssignmentBatch->value)
                ->count(),
        );
        Http::assertSentCount(1);
    }

    public function test_manual_assignment_remains_immediate(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 3],
            ], 200),
        ]);

        $admin = User::factory()->create([
            'name' => 'Ravi Admin',
            'first_name' => 'Ravi',
            'is_active' => true,
        ]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $assignee = $this->createEngineerWithTelegram('Manual Agent', '300400500', RolePermissionSeeder::ROLE_AGENT);
        [$incident] = $this->createCase($assignee, 'RD-MANUAL-1', 'SC-MANUAL-1', 'General', assign: false);

        app(ServiceCaseAssignmentService::class)->assignWithAuditContext(
            incident: $incident,
            assignee: $assignee,
            actor: $admin,
            auditContext: ['assignment_method' => 'manual'],
        );

        Http::assertSentCount(1);
        $this->assertNull(app(IraAssignmentTelegramBatchService::class)->peek($assignee->id));

        $notification = IraNotification::query()
            ->where('user_id', $assignee->id)
            ->where('notification_type', IraNotificationType::ManualAssignment->value)
            ->first();

        $this->assertNotNull($notification);
        $this->assertStringContainsString('Assigned by: Ravi (Admin)', $notification->message);
        $this->assertStringContainsString('New support assigned', $notification->message);
    }

    public function test_separate_engineers_receive_independent_batches(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 4],
            ], 200),
        ]);

        $agentA = $this->createEngineerWithTelegram('Agent A', '111111111');
        $agentB = $this->createEngineerWithTelegram('Agent B', '222222222');

        [$incidentA, $appointmentA] = $this->createCase($agentA, 'RD-A-1', 'SC-A-1', 'Installation');
        [$incidentB, $appointmentB] = $this->createCase($agentB, 'RD-B-1', 'SC-B-1', 'Demo');

        $this->fireSmartAssigned($incidentA, $appointmentA, $agentA);
        $this->fireSmartAssigned($incidentB, $appointmentB, $agentB);

        $this->assertNotNull(app(IraAssignmentTelegramBatchService::class)->peek($agentA->id));
        $this->assertNotNull(app(IraAssignmentTelegramBatchService::class)->peek($agentB->id));

        Carbon::setTestNow(Carbon::parse('2026-07-09 10:05:00', 'Asia/Kolkata'));
        $this->assertSame(2, app(IraAssignmentTelegramBatchService::class)->flushDue());

        $messageA = IraNotification::query()
            ->where('user_id', $agentA->id)
            ->where('notification_type', IraNotificationType::IraAssignmentBatch->value)
            ->value('message');
        $messageB = IraNotification::query()
            ->where('user_id', $agentB->id)
            ->where('notification_type', IraNotificationType::IraAssignmentBatch->value)
            ->value('message');

        $this->assertStringContainsString('• SC-A-1 – Installation', (string) $messageA);
        $this->assertStringNotContainsString('SC-B-1', (string) $messageA);
        $this->assertStringContainsString('• SC-B-1 – Demo', (string) $messageB);
        $this->assertStringNotContainsString('SC-A-1', (string) $messageB);
        Http::assertSentCount(2);
    }

    public function test_opening_desk_before_delay_sends_digest_immediately(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 5],
            ], 200),
        ]);

        $assignee = $this->createEngineerWithTelegram('Early Agent', '555666777');
        [$incident, $appointment] = $this->createCase($assignee, 'RD-EARLY-1', 'SC-EARLY-1', 'Installation');

        $this->fireSmartAssigned($incident, $appointment, $assignee);
        Http::assertNothingSent();
        $this->assertNotNull(app(IraAssignmentTelegramBatchService::class)->peek($assignee->id));

        // Engineer opens Desk before the 5-minute delay expires.
        Carbon::setTestNow(Carbon::parse('2026-07-09 10:02:00', 'Asia/Kolkata'));
        $this->assertTrue(app(IraAssignmentTelegramBatchService::class)->flushForUserIfPending($assignee));

        Http::assertSentCount(1);
        $this->assertNull(app(IraAssignmentTelegramBatchService::class)->peek($assignee->id));

        $notification = IraNotification::query()
            ->where('user_id', $assignee->id)
            ->where('notification_type', IraNotificationType::IraAssignmentBatch->value)
            ->first();

        $this->assertNotNull($notification);
        $this->assertStringContainsString('• SC-EARLY-1 – Installation', $notification->message);
    }

    public function test_scheduler_does_not_resend_after_desk_flush(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 6],
            ], 200),
        ]);

        $assignee = $this->createEngineerWithTelegram('Desk First Agent', '666777888');
        [$incident, $appointment] = $this->createCase($assignee, 'RD-DESK-1', 'SC-DESK-1', 'Demo');

        $this->fireSmartAssigned($incident, $appointment, $assignee);
        $this->assertTrue(app(IraAssignmentTelegramBatchService::class)->flushForUserIfPending($assignee));
        Http::assertSentCount(1);

        Carbon::setTestNow(Carbon::parse('2026-07-09 10:05:00', 'Asia/Kolkata'));
        $this->assertSame(0, app(IraAssignmentTelegramBatchService::class)->flushDue());
        $this->artisan('ira:flush-assignment-telegram-batches')->assertSuccessful();

        $this->assertSame(
            1,
            IraNotification::query()
                ->where('user_id', $assignee->id)
                ->where('notification_type', IraNotificationType::IraAssignmentBatch->value)
                ->count(),
        );
        Http::assertSentCount(1);
    }

    public function test_scheduler_sends_when_engineer_never_opens_desk(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 7],
            ], 200),
        ]);

        $assignee = $this->createEngineerWithTelegram('Away Agent', '777888999');
        [$incident, $appointment] = $this->createCase($assignee, 'RD-AWAY-1', 'SC-AWAY-1', 'Service');

        $this->fireSmartAssigned($incident, $appointment, $assignee);
        Http::assertNothingSent();

        Carbon::setTestNow(Carbon::parse('2026-07-09 10:05:00', 'Asia/Kolkata'));
        $this->assertSame(1, app(IraAssignmentTelegramBatchService::class)->flushDue());

        $this->assertSame(
            1,
            IraNotification::query()
                ->where('user_id', $assignee->id)
                ->where('notification_type', IraNotificationType::IraAssignmentBatch->value)
                ->where('status', IraNotificationStatus::Sent->value)
                ->count(),
        );
        Http::assertSentCount(1);
    }

    private function createEngineerWithTelegram(
        string $name,
        string $chatId,
        string $role = RolePermissionSeeder::ROLE_SUPPORT_SPECIALIST,
    ): User {
        $parts = explode(' ', $name, 2);

        $user = User::factory()->create([
            'name' => $name,
            'first_name' => $parts[0],
            'last_name' => $parts[1] ?? '',
            'telegram_chat_id' => $chatId,
            'telegram_notifications_enabled' => true,
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    /**
     * @return array{0: Incident, 1: SupportAppointment}
     */
    private function createCase(
        User $assignee,
        string $orderId,
        string $referenceNo,
        string $category,
        bool $assign = true,
    ): array {
        $creator = User::factory()->create(['is_active' => true]);
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => 'SN-'.$orderId,
            'product_name' => 'MIS 100',
            'device_model' => 'MIS 100',
            'transaction_id' => null,
            'customer_name' => 'Batch Customer',
            'customer_email' => strtolower($orderId).'@example.com',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => $referenceNo,
            'category' => $category,
            'source' => IncidentSource::Internal,
            'title' => $category,
            'description' => 'Batch telegram case.',
            'status' => IncidentStatus::Open,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
            'assigned_to_user_id' => $assign ? $assignee->id : null,
        ]);

        $appointment = SupportAppointment::query()->create([
            'incident_id' => $incident->id,
            'preferred_date' => now()->addDay()->toDateString(),
            'preferred_time_slot' => SupportAppointmentTimeSlot::Morning,
            'phone_number' => '9876543210',
        ]);

        return [$incident, $appointment];
    }

    private function fireSmartAssigned(Incident $incident, SupportAppointment $appointment, User $assignee): void
    {
        event(new SupportAppointmentSmartAssigned(
            incident: $incident->fresh(['order']),
            appointment: $appointment,
            assignee: $assignee,
            result: \App\Data\Operations\SmartAssignmentResult::assigned(
                assignee: $assignee,
                reasons: ['Available'],
                context: ['factors' => ['Available']],
            ),
        ));
    }
}
