<?php

namespace Tests\Feature;

use App\Enums\CompanyHolidayType;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\IraNotificationStatus;
use App\Enums\IraNotificationType;
use App\Enums\LeaveDuration;
use App\Enums\LeaveRequestStatus;
use App\Enums\OperationQueue;
use App\Models\AuditLog;
use App\Models\CompanyHoliday;
use App\Models\Incident;
use App\Models\IraNotification;
use App\Models\LeaveRequest;
use App\Models\Order;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\ReadModels\Cases\CaseQueueReadModel;
use App\Services\Dashboard\DashboardSnapshotStore;
use App\Services\IncidentReferenceService;
use App\Services\Operations\IraCommunicationService;
use App\Services\Operations\IraNotificationPolicyService;
use App\Services\Operations\LatestServiceReferenceQuery;
use App\Services\Operations\WorkCalendarService;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReadyQueueTelegramDigestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        config([
            'services.telegram.bot_token' => 'test-bot-token',
            'ira.communication.cooldown_minutes' => 60,
            'app.schedule_timezone' => 'Asia/Kolkata',
            'app.timezone' => 'Asia/Kolkata',
        ]);

        $this->enableTelegramNotifications();
        Cache::flush();
        app(DashboardSnapshotStore::class)->forget();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_digest_count_uses_ready_queue_action_required_not_pending_admin(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 11:00:00', 'Asia/Kolkata'));
        $this->fakeTelegram();

        $admin = $this->createOperationalAdmin('910001', RolePermissionSeeder::ROLE_ADMIN);
        $this->createDaySchedule($admin);

        $creator = $this->createOperationalAdmin('910099', RolePermissionSeeder::ROLE_ADMIN);
        $this->createReadyIncident('RD-RQ-DIGEST-READY', $creator);
        $this->createReadyIncident('INQ-RQ-DIGEST-PENDING', $creator);

        app(DashboardSnapshotStore::class)->forget();

        $readyCount = app(CaseQueueReadModel::class)->queueCount(OperationQueue::ActionRequired);
        $pendingAdmin = (int) (app(CaseQueueReadModel::class)->filterCounts()['pending_admin'] ?? 0);

        $this->assertSame(1, $readyCount);
        $this->assertGreaterThan($readyCount, $pendingAdmin);

        app(IraCommunicationService::class)->sendReadyQueueDigest();

        Http::assertSent(function (Request $request) use ($readyCount, $pendingAdmin): bool {
            $text = (string) $request['text'];

            return str_contains($text, 'Ready Queue: '.$readyCount)
                && ! str_contains($text, 'Ready Queue: '.$pendingAdmin);
        });
    }

    public function test_latest_service_reference_is_the_newest_assigned_audit(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 14:27:00', 'Asia/Kolkata'));

        $olderAgent = $this->createSupportAgent('Older Agent');
        $latestAgent = $this->createSupportAgent('Rahul Kumar');

        $olderOrder = $this->createOrder('RD-SR-OLD');
        $latestOrder = $this->createOrder('RD-SR-NEW');

        $this->recordServiceReferenceAssigned(
            $olderOrder,
            $olderAgent,
            'SR-OLD',
            Carbon::parse('2026-07-09 11:00:00', 'Asia/Kolkata'),
        );
        $this->recordServiceReferenceAssigned(
            $latestOrder,
            $latestAgent,
            'SR12345',
            Carbon::parse('2026-07-09 14:27:00', 'Asia/Kolkata'),
        );

        $latest = app(LatestServiceReferenceQuery::class)->latest();

        $this->assertNotNull($latest);
        $this->assertSame('SR12345', $latest->serviceReference);
        $this->assertSame('Rahul', $latest->agentName);
        $this->assertSame(
            '2:27 PM',
            $latest->addedAt?->timezone('Asia/Kolkata')->format('g:i A'),
        );
    }

    public function test_latest_service_reference_skips_blank_transaction_id_rows(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 14:27:00', 'Asia/Kolkata'));

        $blankAgent = $this->createSupportAgent('Blank Agent');
        $validAgent = $this->createSupportAgent('Rahul Kumar');

        $blankOrder = $this->createOrder('RD-SR-BLANK');
        $validOrder = $this->createOrder('RD-SR-VALID');

        $this->recordServiceReferenceAssigned(
            $validOrder,
            $validAgent,
            'SR12345',
            Carbon::parse('2026-07-09 11:00:00', 'Asia/Kolkata'),
        );
        $this->recordServiceReferenceAssigned(
            $blankOrder,
            $blankAgent,
            '',
            Carbon::parse('2026-07-09 14:27:00', 'Asia/Kolkata'),
        );

        $latest = app(LatestServiceReferenceQuery::class)->latest();

        $this->assertNotNull($latest);
        $this->assertSame('SR12345', $latest->serviceReference);
        $this->assertSame('Rahul', $latest->agentName);
    }

    public function test_latest_service_reference_is_empty_when_none_exist(): void
    {
        $this->assertNull(app(LatestServiceReferenceQuery::class)->latest());
    }

    public function test_digest_message_includes_latest_service_ref_agent_and_time(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 14:30:00', 'Asia/Kolkata'));
        $this->fakeTelegram();

        $admin = $this->createOperationalAdmin('910002', RolePermissionSeeder::ROLE_ADMIN);
        $this->createDaySchedule($admin);
        $this->createReadyIncident('RD-RQ-DIGEST-MSG', $admin);

        $agent = $this->createSupportAgent('Rahul Kumar');
        $this->recordServiceReferenceAssigned(
            $this->createOrder('RD-SR-MSG'),
            $agent,
            'SR12345',
            Carbon::parse('2026-07-09 14:27:00', 'Asia/Kolkata'),
        );

        app(DashboardSnapshotStore::class)->forget();
        app(IraCommunicationService::class)->sendReadyQueueDigest();

        Http::assertSent(function (Request $request): bool {
            $text = (string) $request['text'];

            return str_contains($text, "Ready Queue Update\n")
                && str_contains($text, 'Ready Queue: ')
                && str_contains($text, 'Latest Service Ref: SR12345')
                && str_contains($text, 'Agent: Rahul')
                && str_contains($text, 'Added: 2:27 PM')
                && ! str_contains($text, 'Customer:')
                && ! str_contains($text, 'New support assigned');
        });
    }

    public function test_digest_uses_empty_latest_service_ref_representation_when_none_exist(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 11:00:00', 'Asia/Kolkata'));
        $this->fakeTelegram();

        $admin = $this->createOperationalAdmin('910003', RolePermissionSeeder::ROLE_ADMIN);
        $this->createDaySchedule($admin);
        $this->createReadyIncident('RD-RQ-DIGEST-EMPTY-REF', $admin);
        app(DashboardSnapshotStore::class)->forget();

        app(IraCommunicationService::class)->sendReadyQueueDigest();

        Http::assertSent(function (Request $request): bool {
            $text = (string) $request['text'];

            return str_contains($text, 'Latest Service Ref: —')
                && str_contains($text, 'Agent: —')
                && str_contains($text, 'Added: —');
        });
    }

    public function test_digest_targets_active_admin_and_operations_admin_only(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 11:00:00', 'Asia/Kolkata'));
        $this->fakeTelegram();

        $admin = $this->createOperationalAdmin('910004', RolePermissionSeeder::ROLE_ADMIN);
        $opsAdmin = $this->createOperationalAdmin('910005', RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);
        $superadmin = $this->createOperationalAdmin('910006', RolePermissionSeeder::ROLE_SUPERADMIN);
        $agent = $this->createSupportAgent('Digest Agent', '910007');
        $inactive = $this->createOperationalAdmin('910008', RolePermissionSeeder::ROLE_ADMIN, active: false);
        $telegramDisabled = $this->createOperationalAdmin(
            '910009',
            RolePermissionSeeder::ROLE_ADMIN,
            telegramEnabled: false,
        );

        foreach ([$admin, $opsAdmin, $superadmin, $agent, $inactive, $telegramDisabled] as $user) {
            $this->createDaySchedule($user);
        }

        $this->createReadyIncident('RD-RQ-DIGEST-RECIP', $admin);
        app(DashboardSnapshotStore::class)->forget();

        app(IraCommunicationService::class)->sendReadyQueueDigest();

        $this->assertSame(['910004', '910005'], $this->sentChatIds());

        $this->assertDatabaseMissing('ira_notifications', [
            'user_id' => $superadmin->id,
            'notification_type' => IraNotificationType::ReadyQueueDigest->value,
        ]);
        $this->assertDatabaseMissing('ira_notifications', [
            'user_id' => $agent->id,
            'notification_type' => IraNotificationType::ReadyQueueDigest->value,
        ]);
        $this->assertDatabaseMissing('ira_notifications', [
            'user_id' => $inactive->id,
            'notification_type' => IraNotificationType::ReadyQueueDigest->value,
        ]);
    }

    public function test_recipient_working_hours_are_evaluated_independently(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 21:00:00', 'Asia/Kolkata'));
        $this->fakeTelegram();

        $dayAdmin = $this->createOperationalAdmin('910010', RolePermissionSeeder::ROLE_ADMIN, 'Day Admin');
        $eveningAdmin = $this->createOperationalAdmin('910011', RolePermissionSeeder::ROLE_OPERATIONS_ADMIN, 'Evening Admin');

        $this->createSchedule($dayAdmin, '09:00:00', '18:00:00');
        $this->createSchedule($eveningAdmin, '10:00:00', '00:00:00');

        $this->createReadyIncident('RD-RQ-DIGEST-HOURS', $dayAdmin);
        app(DashboardSnapshotStore::class)->forget();

        $results = app(IraCommunicationService::class)->sendReadyQueueDigest();

        $this->assertSame(['910011'], $this->sentChatIds());

        $dayResult = collect($results)->first(
            fn (IraNotification $notification): bool => $notification->user_id === $dayAdmin->id,
        );
        $this->assertNotNull($dayResult);
        $this->assertSame(IraNotificationStatus::Skipped, $dayResult->status);
        $this->assertStringContainsString('working hours', (string) $dayResult->error_message);

        $this->assertDatabaseHas('ira_notifications', [
            'user_id' => $eveningAdmin->id,
            'notification_type' => IraNotificationType::ReadyQueueDigest->value,
            'status' => IraNotificationStatus::Sent->value,
        ]);
    }

    public function test_day_admin_can_receive_digest_while_evening_admin_is_outside_hours(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 09:30:00', 'Asia/Kolkata'));
        $this->fakeTelegram();

        $dayAdmin = $this->createOperationalAdmin('910012', RolePermissionSeeder::ROLE_ADMIN);
        $eveningAdmin = $this->createOperationalAdmin('910013', RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        $this->createSchedule($dayAdmin, '09:00:00', '18:00:00');
        $this->createSchedule($eveningAdmin, '10:00:00', '00:00:00');

        $this->createReadyIncident('RD-RQ-DIGEST-HOURS-AM', $dayAdmin);
        app(DashboardSnapshotStore::class)->forget();

        app(IraCommunicationService::class)->sendReadyQueueDigest();

        $this->assertSame(['910012'], $this->sentChatIds());
    }

    public function test_admin_without_work_schedule_does_not_receive_digest(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 11:00:00', 'Asia/Kolkata'));
        $this->fakeTelegram();

        $unscheduled = $this->createOperationalAdmin('910018', RolePermissionSeeder::ROLE_ADMIN);
        $scheduled = $this->createOperationalAdmin('910019', RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);
        $this->createDaySchedule($scheduled);

        $this->assertTrue(app(WorkCalendarService::class)->isEligibleForAssignment($unscheduled));
        $this->assertTrue(app(IraNotificationPolicyService::class)->canNotifyNow($unscheduled));

        $this->createReadyIncident('RD-RQ-DIGEST-NO-SCHEDULE', $scheduled);
        app(DashboardSnapshotStore::class)->forget();

        $results = app(IraCommunicationService::class)->sendReadyQueueDigest();

        $this->assertSame(['910019'], $this->sentChatIds());

        $skipped = collect($results)->first(
            fn (IraNotification $notification): bool => $notification->user_id === $unscheduled->id,
        );
        $this->assertNotNull($skipped);
        $this->assertSame(IraNotificationStatus::Skipped, $skipped->status);
    }

    public function test_lunch_period_does_not_receive_digest(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 13:45:00', 'Asia/Kolkata'));
        $this->fakeTelegram();

        $onLunch = $this->createOperationalAdmin('910020', RolePermissionSeeder::ROLE_ADMIN);
        $working = $this->createOperationalAdmin('910021', RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        $this->createSchedule($onLunch, '09:00:00', '18:00:00', '13:30:00', '14:00:00');
        $this->createSchedule($working, '09:00:00', '18:00:00');

        $this->createReadyIncident('RD-RQ-DIGEST-LUNCH', $working);
        app(DashboardSnapshotStore::class)->forget();

        app(IraCommunicationService::class)->sendReadyQueueDigest();

        $this->assertSame(['910021'], $this->sentChatIds());
    }

    public function test_approved_leave_does_not_receive_digest(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 11:00:00', 'Asia/Kolkata'));
        $this->fakeTelegram();

        $onLeave = $this->createOperationalAdmin('910022', RolePermissionSeeder::ROLE_ADMIN);
        $working = $this->createOperationalAdmin('910023', RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);
        $this->createDaySchedule($onLeave);
        $this->createDaySchedule($working);

        LeaveRequest::query()->create([
            'user_id' => $onLeave->id,
            'start_date' => '2026-07-09',
            'end_date' => '2026-07-09',
            'reason' => 'Approved leave',
            'duration' => LeaveDuration::FullDay,
            'status' => LeaveRequestStatus::Approved,
        ]);

        $this->createReadyIncident('RD-RQ-DIGEST-LEAVE', $working);
        app(DashboardSnapshotStore::class)->forget();

        app(IraCommunicationService::class)->sendReadyQueueDigest();

        $this->assertSame(['910023'], $this->sentChatIds());
    }

    public function test_company_holiday_does_not_send_digest(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 11:00:00', 'Asia/Kolkata'));
        $this->fakeTelegram();

        $admin = $this->createOperationalAdmin('910024', RolePermissionSeeder::ROLE_ADMIN);
        $this->createDaySchedule($admin);

        CompanyHoliday::query()->create([
            'holiday_date' => '2026-07-09',
            'name' => 'Company Holiday',
            'type' => CompanyHolidayType::Company,
        ]);

        $this->createReadyIncident('RD-RQ-DIGEST-HOLIDAY', $admin);
        app(DashboardSnapshotStore::class)->forget();

        $results = app(IraCommunicationService::class)->sendReadyQueueDigest();

        Http::assertNothingSent();
        $this->assertNotSame([], $results);
        $this->assertSame(IraNotificationStatus::Skipped, $results[0]->status);
    }

    public function test_zero_ready_queue_sends_no_digest_telegram(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 11:00:00', 'Asia/Kolkata'));
        $this->fakeTelegram();

        $admin = $this->createOperationalAdmin('910014', RolePermissionSeeder::ROLE_ADMIN);
        $this->createDaySchedule($admin);

        app(DashboardSnapshotStore::class)->forget();
        $this->assertSame(0, app(CaseQueueReadModel::class)->queueCount(OperationQueue::ActionRequired));

        $results = app(IraCommunicationService::class)->sendReadyQueueDigest();

        $this->assertSame([], $results);
        Http::assertNothingSent();
        $this->assertDatabaseMissing('ira_notifications', [
            'notification_type' => IraNotificationType::ReadyQueueDigest->value,
        ]);
    }

    public function test_same_thirty_minute_slot_does_not_send_twice(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 11:00:00', 'Asia/Kolkata'));
        $this->fakeTelegram();

        $admin = $this->createOperationalAdmin('910015', RolePermissionSeeder::ROLE_ADMIN);
        $this->createDaySchedule($admin);
        $this->createReadyIncident('RD-RQ-DIGEST-DEDUP', $admin);
        app(DashboardSnapshotStore::class)->forget();

        $service = app(IraCommunicationService::class);
        $first = $service->sendReadyQueueDigest();
        $second = $service->sendReadyQueueDigest();

        $this->assertCount(1, $first);
        $this->assertSame(IraNotificationStatus::Sent, $first[0]->status);
        $this->assertSame([], $second);
        Http::assertSentCount(1);
    }

    public function test_next_thirty_minute_slot_can_send_again(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 11:00:00', 'Asia/Kolkata'));
        $this->fakeTelegram();

        $admin = $this->createOperationalAdmin('910016', RolePermissionSeeder::ROLE_ADMIN);
        $this->createDaySchedule($admin);
        $this->createReadyIncident('RD-RQ-DIGEST-NEXT-SLOT', $admin);
        app(DashboardSnapshotStore::class)->forget();

        $service = app(IraCommunicationService::class);
        $first = $service->sendReadyQueueDigest();

        Carbon::setTestNow(Carbon::parse('2026-07-09 11:30:00', 'Asia/Kolkata'));
        $second = $service->sendReadyQueueDigest();

        $this->assertCount(1, $first);
        $this->assertCount(1, $second);
        $this->assertSame(IraNotificationStatus::Sent, $second[0]->status);
        Http::assertSentCount(2);
    }

    public function test_ready_queue_digest_command_is_scheduled_every_thirty_minutes_in_kolkata(): void
    {
        $this->artisan('schedule:list')->assertSuccessful();

        $event = $this->findScheduledEvent('ira:send-ready-queue-digest');

        $this->assertSame('*/30 * * * *', $event->getExpression());
        $this->assertSame('Asia/Kolkata', $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_scheduler_fires_every_thirty_minutes_including_outside_a_single_admin_shift(): void
    {
        $this->artisan('schedule:list')->assertSuccessful();
        $event = $this->findScheduledEvent('ira:send-ready-queue-digest');

        Carbon::setTestNow(Carbon::parse('2026-08-21 10:00:00', 'Asia/Kolkata'));
        $this->assertTrue($event->isDue($this->app));

        Carbon::setTestNow(Carbon::parse('2026-08-21 10:15:00', 'Asia/Kolkata'));
        $this->assertFalse($event->isDue($this->app));

        Carbon::setTestNow(Carbon::parse('2026-08-21 22:00:00', 'Asia/Kolkata'));
        $this->assertTrue($event->isDue($this->app));
    }

    public function test_digest_command_is_not_folded_into_light_tick_and_does_not_reuse_ops_digest(): void
    {
        $this->artisan('schedule:list')->assertSuccessful();
        $events = collect(app(Schedule::class)->events());

        $digest = $this->findScheduledEvent('ira:send-ready-queue-digest');
        $lightTick = $this->findScheduledEvent('schedule:light-tick');
        $opsMorning = $this->findScheduledEvent('ira:send-ops-digest --period=morning');

        $this->assertNotSame($digest, $lightTick);
        $this->assertNotSame($digest, $opsMorning);
        $this->assertFalse(str_contains((string) $lightTick->command, 'ira:send-ready-queue-digest'));
    }

    public function test_artisan_command_sends_digest_to_operational_recipients(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 11:00:00', 'Asia/Kolkata'));
        $this->fakeTelegram();

        $admin = $this->createOperationalAdmin('910017', RolePermissionSeeder::ROLE_ADMIN);
        $this->createDaySchedule($admin);
        $this->createReadyIncident('RD-RQ-DIGEST-CMD', $admin);
        app(DashboardSnapshotStore::class)->forget();

        $this->artisan('ira:send-ready-queue-digest')->assertSuccessful();

        Http::assertSentCount(1);
        $this->assertSame(['910017'], $this->sentChatIds());
    }

    private function fakeTelegram(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 1],
            ], 200),
        ]);
    }

    /**
     * @return list<string>
     */
    private function sentChatIds(): array
    {
        return collect(Http::recorded())
            ->map(fn (array $pair): string => (string) $pair[0]['chat_id'])
            ->values()
            ->all();
    }

    private function createOperationalAdmin(
        string $chatId,
        string $role,
        string $name = 'Ops Admin',
        bool $active = true,
        bool $telegramEnabled = true,
    ): User {
        $user = User::factory()->create([
            'name' => $name,
            'telegram_chat_id' => $telegramEnabled ? $chatId : null,
            'telegram_notifications_enabled' => $telegramEnabled,
            'is_active' => $active,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function createSupportAgent(string $name, string $chatId = '919000001'): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'telegram_chat_id' => $chatId,
            'telegram_notifications_enabled' => true,
            'is_active' => true,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_SUPPORT_SPECIALIST);

        return $user;
    }

    private function createDaySchedule(User $user): TeamMemberWorkSchedule
    {
        return $this->createSchedule($user, '09:00:00', '18:00:00');
    }

    private function createSchedule(
        User $user,
        string $start,
        string $end,
        ?string $lunchStart = null,
        ?string $lunchEnd = null,
    ): TeamMemberWorkSchedule {
        return TeamMemberWorkSchedule::query()->create([
            'user_id' => $user->id,
            'effective_from' => '2026-01-01',
            'work_start_time' => $start,
            'work_end_time' => $end,
            'lunch_start_time' => $lunchStart,
            'lunch_end_time' => $lunchEnd,
            'weekly_off_days' => [Carbon::SUNDAY],
        ]);
    }

    private function createReadyIncident(string $orderId, User $assignee): Incident
    {
        $order = Order::query()->create([
            'order_id' => $orderId,
            'customer_name' => 'Ready Queue Customer',
            'serial_number' => (string) (7_881_000 + (abs(crc32($orderId)) % 9_000)),
            'product_name' => 'MFS110',
            'device_model' => 'MFS110',
            'status' => 'active',
            'created_by' => $assignee->id,
        ]);

        app(RadiumBoxOrderEnrichmentSyncStore::class)->markSynced($order->id);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Ready Queue digest test',
            'description' => 'Ready Queue digest test.',
            'status' => IncidentStatus::Open,
            'created_by' => $assignee->id,
            'updated_by' => $assignee->id,
            'assigned_to_user_id' => $assignee->id,
        ]);
    }

    private function createOrder(string $orderId): Order
    {
        $creator = User::factory()->create(['is_active' => true]);

        return Order::query()->create([
            'order_id' => $orderId,
            'customer_name' => 'Service Ref Customer',
            'serial_number' => 'SN-'.$orderId,
            'product_name' => 'MFS110',
            'device_model' => 'MFS110',
            'status' => 'active',
            'created_by' => $creator->id,
        ]);
    }

    private function recordServiceReferenceAssigned(
        Order $order,
        User $agent,
        string $serviceReference,
        Carbon $at,
    ): void {
        $log = new AuditLog;
        $log->fill([
            'user_id' => $agent->id,
            'event' => 'service_reference.assigned',
            'auditable_type' => $order->getMorphClass(),
            'auditable_id' => $order->id,
            'new_values' => [
                'transaction_id' => $serviceReference,
                'completed_at' => $at->toIso8601String(),
            ],
        ]);
        $log->created_at = $at;
        $log->save();
    }

    private function findScheduledEvent(string $needle): Event
    {
        $events = collect(app(Schedule::class)->events());
        $event = $events->first(function (Event $event) use ($needle): bool {
            foreach ([
                (string) ($event->command ?? ''),
                (string) ($event->description ?? ''),
                (string) $event->getSummaryForDisplay(),
            ] as $haystack) {
                if (str_contains($haystack, $needle)) {
                    return true;
                }
            }

            return false;
        });

        $this->assertNotNull($event, "Scheduled event not found: {$needle}");

        return $event;
    }
}
