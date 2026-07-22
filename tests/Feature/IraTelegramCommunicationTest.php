<?php

namespace Tests\Feature;

use App\Data\Operations\IraCommunicationInput;
use App\Data\Operations\IraMorningBriefing;
use App\Data\Operations\IraOperationalRisk;
use App\Data\Operations\IraOperationalSnapshotData;
use App\Enums\AI\AIRiskLevel;
use App\Enums\IraNotificationStatus;
use App\Enums\IraNotificationType;
use App\Enums\IraRiskCategory;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\SupportAppointmentTimeSlot;
use App\Enums\TeamAvailabilityStatus;
use App\Events\Operations\SupportAppointmentSmartAssigned;
use App\Models\Incident;
use App\Models\IraNotification;
use App\Models\Order;
use App\Models\SupportAppointment;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\Operations\IraCommunicationService;
use App\Services\Operations\IraOperationsBrainService;
use Database\Seeders\RolePermissionSeeder;
use App\Listeners\Operations\DispatchIraSmartAssignmentNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class IraTelegramCommunicationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        config([
            'services.telegram.bot_token' => 'test-bot-token',
            'ira.communication.cooldown_minutes' => 60,
        ]);

        $this->enableTelegramNotifications();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Cache::flush();

        parent::tearDown();
    }

    public function test_telegram_message_sends(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 42],
            ], 200),
        ]);

        $owner = $this->createOwnerWithTelegram('123456789');

        $results = app(IraCommunicationService::class)->dispatch(new IraCommunicationInput(
            event: IraNotificationType::RiskAlert,
            insight: new IraOperationalRisk(
                key: 'customer.sla_danger',
                title: 'SLA Breach Risk',
                category: IraRiskCategory::Customer,
                severity: AIRiskLevel::High,
                message: '3 cases risk SLA breach.',
                context: ['overdue' => 2, 'warning' => 1],
            ),
            context: ['dedupe_key' => 'customer.sla_danger'],
        ));

        $this->assertCount(1, $results);
        $this->assertSame(IraNotificationStatus::Sent, $results[0]->status);
        Http::assertSentCount(1);

        $this->assertDatabaseHas('ira_notifications', [
            'user_id' => $owner->id,
            'notification_type' => IraNotificationType::RiskAlert->value,
            'status' => IraNotificationStatus::Sent->value,
        ]);
    }

    public function test_disabled_user_is_skipped(): void
    {
        Http::fake();

        $owner = User::factory()->create([
            'telegram_chat_id' => '123456789',
            'telegram_notifications_enabled' => false,
            'is_active' => true,
        ]);
        $owner->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $results = app(IraCommunicationService::class)->dispatch(new IraCommunicationInput(
            event: IraNotificationType::RiskAlert,
            insight: new IraOperationalRisk(
                key: 'customer.sla_danger',
                title: 'SLA Breach Risk',
                category: IraRiskCategory::Customer,
                severity: AIRiskLevel::High,
                message: '3 cases risk SLA breach.',
                context: ['overdue' => 2, 'warning' => 1],
            ),
            context: ['dedupe_key' => 'customer.sla_danger'],
        ));

        $this->assertCount(1, $results);
        $this->assertSame(IraNotificationStatus::Skipped, $results[0]->status);
        Http::assertNothingSent();
    }

    public function test_daily_briefing_generated(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-05 08:00:00', 'Asia/Kolkata'));

        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 99],
            ], 200),
        ]);

        $owner = $this->createOwnerWithTelegram('555001', 'Ravi Owner');
        $agent = $this->createSupportAgent('Briefing Agent');
        $this->createIncidentFor($agent, 'RD-BRIEF-TG');

        $briefing = app(IraOperationsBrainService::class)->briefing(useCache: false);
        $results = app(IraCommunicationService::class)->sendDailyBriefing($owner, $briefing);

        $this->assertCount(1, $results);
        $this->assertSame(IraNotificationStatus::Sent, $results[0]->status);
        $this->assertStringContainsString('Good morning Ravi', $results[0]->message);
        $this->assertStringContainsString('📊 Operations', $results[0]->message);
        $this->assertStringContainsString('👥 Team', $results[0]->message);
        $this->assertStringNotContainsString('0 active', $results[0]->message);
        $this->assertStringNotContainsString('SLA risks', $results[0]->message);
        $this->assertLessThanOrEqual(900, strlen($results[0]->message));
        $this->assertStringContainsString('Good morning', $briefing->greeting);

        $this->mock(IraOperationsBrainService::class, function ($mock) use ($briefing): void {
            $mock->shouldReceive('briefing')->once()->andReturn($briefing);
        });

        $this->artisan('ira:send-daily-briefing')->assertSuccessful();
    }

    public function test_ops_digest_sent_to_operational_recipients_only(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 08:15:00', 'Asia/Kolkata'));

        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 101],
            ], 200),
        ]);

        $owner = $this->createOwnerWithTelegram('111000111', 'Owner User');
        $opsAdmin = User::factory()->create([
            'name' => 'Shipra Kumari',
            'first_name' => 'Shipra',
            'last_name' => 'Kumari',
            'telegram_chat_id' => '222000222',
            'telegram_notifications_enabled' => true,
            'is_active' => true,
        ]);
        $opsAdmin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $opsAdmin->assignRole(RolePermissionSeeder::ROLE_CUSTOMER_COORDINATOR);

        $agent = User::factory()->create([
            'name' => 'Digest Agent',
            'telegram_chat_id' => '333000333',
            'telegram_notifications_enabled' => true,
            'is_active' => true,
        ]);
        $agent->assignRole(RolePermissionSeeder::ROLE_SUPPORT_SPECIALIST);
        $this->createIncidentFor($agent, 'RD-OPS-DIGEST');

        $briefing = app(IraOperationsBrainService::class)->briefing(useCache: false);
        $results = app(IraCommunicationService::class)->sendOpsDigest($opsAdmin, $briefing, 'open');

        $this->assertCount(1, $results);
        $this->assertSame(IraNotificationStatus::Sent, $results[0]->status);
        $this->assertStringContainsString('Operations Digest', $results[0]->message);
        $this->assertStringContainsString('Waiting backlog', $results[0]->message);
        $this->assertStringContainsString('SLA risk', $results[0]->message);
        $this->assertStringContainsString('Missed appointments', $results[0]->message);

        $this->assertDatabaseMissing('ira_notifications', [
            'user_id' => $owner->id,
            'notification_type' => IraNotificationType::OpsDigest->value,
        ]);

        $this->assertDatabaseMissing('ira_notifications', [
            'user_id' => $agent->id,
            'notification_type' => IraNotificationType::OpsDigest->value,
        ]);

        $this->mock(IraOperationsBrainService::class, function ($mock) use ($briefing): void {
            $mock->shouldReceive('briefing')->once()->andReturn($briefing);
        });

        $this->artisan('ira:send-ops-digest --period=open')->assertSuccessful();
    }

    public function test_high_waiting_risk_is_excluded_from_hourly_alerts(): void
    {
        Http::fake();

        $opsAdmin = User::factory()->create([
            'name' => 'Ops Admin',
            'telegram_chat_id' => '444000444',
            'telegram_notifications_enabled' => true,
            'is_active' => true,
        ]);
        $opsAdmin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $briefing = new IraMorningBriefing(
            greeting: 'Good morning.',
            summary: 'Operations need attention.',
            healthStatus: 'warning',
            highlights: [],
            risks: [
                new IraOperationalRisk(
                    key: 'customer.high_waiting',
                    title: 'Customers Waiting Too Long',
                    category: IraRiskCategory::Customer,
                    severity: AIRiskLevel::Medium,
                    message: '122 customers are waiting for a response.',
                    context: ['waiting' => 122, 'threshold' => 50],
                ),
            ],
            recommendations: [],
            snapshot: new IraOperationalSnapshotData(
                date: '2026-07-09',
                operations: ['waiting' => 122],
                team: ['available' => 2],
                performance: ['completed_cases' => 0],
            ),
        );

        $results = app(IraCommunicationService::class)->sendRiskAlerts($briefing);

        $this->assertSame([], $results);
        Http::assertNothingSent();
        $this->assertDatabaseMissing('ira_notifications', [
            'user_id' => $opsAdmin->id,
            'notification_type' => IraNotificationType::WaitingCustomerRisk->value,
        ]);
    }

    public function test_ops_digest_cooldown_prevents_same_period_resend_within_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 08:15:00', 'Asia/Kolkata'));

        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 103],
            ], 200),
        ]);

        $opsAdmin = User::factory()->create([
            'name' => 'Ops Admin',
            'telegram_chat_id' => '444000444',
            'telegram_notifications_enabled' => true,
            'is_active' => true,
        ]);
        $opsAdmin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $briefing = new IraMorningBriefing(
            greeting: 'Good morning.',
            summary: 'Operations digest.',
            healthStatus: 'warning',
            highlights: [],
            risks: [],
            recommendations: [],
            snapshot: new IraOperationalSnapshotData(
                date: '2026-07-09',
                operations: ['waiting' => 122, 'overdue' => 44],
                team: ['available' => 2],
                performance: ['completed_cases' => 0],
            ),
        );

        $service = app(IraCommunicationService::class);
        $first = $service->sendOpsDigest($opsAdmin, $briefing, 'open');
        $second = $service->sendOpsDigest($opsAdmin, $briefing, 'open');

        $this->assertCount(1, $first);
        $this->assertSame(IraNotificationStatus::Sent, $first[0]->status);
        $this->assertSame([], $second);

        Carbon::setTestNow(Carbon::parse('2026-07-09 09:30:00', 'Asia/Kolkata'));

        $third = $service->sendOpsDigest($opsAdmin, $briefing, 'open');

        $this->assertSame([], $third);
        $this->assertSame(
            1,
            IraNotification::query()
                ->where('user_id', $opsAdmin->id)
                ->where('notification_type', IraNotificationType::OpsDigest->value)
                ->count(),
        );
        Http::assertSentCount(1);
    }

    public function test_smart_assignment_notification_is_batched_then_flushed(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 77],
            ], 200),
        ]);

        Carbon::setTestNow(Carbon::parse('2026-07-09 10:00:00', 'Asia/Kolkata'));
        config([
            'ira.communication.assignment_telegram_batch.enabled' => true,
            'ira.communication.assignment_telegram_batch.delay_minutes' => 5,
        ]);

        $assignee = User::factory()->create([
            'name' => 'Assigned Agent',
            'telegram_chat_id' => '888777666',
            'telegram_notifications_enabled' => true,
            'is_active' => true,
        ]);
        $assignee->assignRole(RolePermissionSeeder::ROLE_SUPPORT_SPECIALIST);

        [$incident, $appointment] = $this->createAssignmentFixtures($assignee);
        $incident->update(['category' => 'Installation']);

        event(new SupportAppointmentSmartAssigned(
            incident: $incident->fresh(),
            appointment: $appointment,
            assignee: $assignee,
            result: \App\Data\Operations\SmartAssignmentResult::assigned(
                assignee: $assignee,
                reasons: ['Available'],
                context: ['factors' => ['Available']],
            ),
        ));

        Http::assertNothingSent();
        $this->assertNotNull(app(\App\Services\Operations\IraAssignmentTelegramBatchService::class)->peek($assignee->id));

        Carbon::setTestNow(Carbon::parse('2026-07-09 10:05:00', 'Asia/Kolkata'));
        $this->artisan('ira:flush-assignment-telegram-batches')->assertSuccessful();

        $notification = IraNotification::query()
            ->where('user_id', $assignee->id)
            ->where('notification_type', IraNotificationType::IraAssignmentBatch->value)
            ->first();

        $this->assertNotNull($notification);
        $this->assertSame(IraNotificationStatus::Sent, $notification->status);
        $this->assertStringContainsString('🤖 IRA assigned new support cases', $notification->message);
        $this->assertStringContainsString('• '.$incident->reference_no.' – Installation', $notification->message);
        $this->assertStringContainsString('Open Radium Desk to view your updated queue.', $notification->message);
        Http::assertSentCount(1);
    }

    public function test_support_appointment_smart_assigned_batches_into_one_telegram_notification(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 101],
            ], 200),
        ]);

        Carbon::setTestNow(Carbon::parse('2026-07-09 10:00:00', 'Asia/Kolkata'));
        config([
            'system_settings.notifications.telegram.enabled' => true,
            'ira.communication.assignment_telegram_batch.enabled' => true,
            'ira.communication.assignment_telegram_batch.delay_minutes' => 5,
        ]);

        $assignee = User::factory()->create([
            'name' => 'Single Delivery Agent',
            'telegram_chat_id' => '444555666',
            'telegram_notifications_enabled' => true,
            'is_active' => true,
        ]);
        $assignee->assignRole(RolePermissionSeeder::ROLE_SUPPORT_SPECIALIST);

        [$incident, $appointment] = $this->createAssignmentFixtures($assignee);

        event(new SupportAppointmentSmartAssigned(
            incident: $incident,
            appointment: $appointment,
            assignee: $assignee,
            result: \App\Data\Operations\SmartAssignmentResult::assigned(
                assignee: $assignee,
                reasons: ['Available'],
                context: ['factors' => ['Available']],
            ),
        ));

        Http::assertNothingSent();

        Carbon::setTestNow(Carbon::parse('2026-07-09 10:05:00', 'Asia/Kolkata'));
        $this->artisan('ira:flush-assignment-telegram-batches')->assertSuccessful();

        Http::assertSentCount(1);

        $this->assertSame(
            1,
            IraNotification::query()
                ->where('user_id', $assignee->id)
                ->where('notification_type', IraNotificationType::IraAssignmentBatch->value)
                ->where('status', IraNotificationStatus::Sent->value)
                ->count(),
        );
    }

    public function test_legacy_support_assignment_telegram_listener_is_not_registered(): void
    {
        $this->assertFalse(
            class_exists(\App\Listeners\Operations\DispatchSupportAssignmentTelegramNotification::class),
        );

        $registered = collect(app('events')->getRawListeners()[SupportAppointmentSmartAssigned::class] ?? [])
            ->flatten()
            ->map(function (mixed $listener): string {
                if (is_string($listener)) {
                    return $listener;
                }

                if (is_array($listener)) {
                    $target = $listener[0] ?? null;

                    return is_object($target) ? $target::class : (string) $target;
                }

                if (is_object($listener)) {
                    return $listener::class;
                }

                return (string) $listener;
            })
            ->implode("\n");

        $this->assertStringContainsString(DispatchIraSmartAssignmentNotification::class, $registered);
        $this->assertStringNotContainsString('DispatchSupportAssignmentTelegramNotification', $registered);
    }

    public function test_support_appointment_smart_assigned_does_not_emit_legacy_telegram_dispatch_error(): void
    {
        Log::spy();

        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 101],
            ], 200),
        ]);

        Carbon::setTestNow(Carbon::parse('2026-07-09 10:00:00', 'Asia/Kolkata'));
        config([
            'system_settings.notifications.telegram.enabled' => true,
            'ira.communication.assignment_telegram_batch.enabled' => true,
            'ira.communication.assignment_telegram_batch.delay_minutes' => 5,
        ]);

        $assignee = User::factory()->create([
            'name' => 'Legacy Path Agent',
            'telegram_chat_id' => '111222333',
            'telegram_notifications_enabled' => true,
            'is_active' => true,
        ]);
        $assignee->assignRole(RolePermissionSeeder::ROLE_SUPPORT_SPECIALIST);

        [$incident, $appointment] = $this->createAssignmentFixtures($assignee);

        event(new SupportAppointmentSmartAssigned(
            incident: $incident,
            appointment: $appointment,
            assignee: $assignee,
            result: \App\Data\Operations\SmartAssignmentResult::assigned(
                assignee: $assignee,
                reasons: ['Available'],
                context: ['factors' => ['Available']],
            ),
        ));

        Log::shouldNotHaveReceived('error', ['smart_assignment.telegram_dispatch_failed']);

        Carbon::setTestNow(Carbon::parse('2026-07-09 10:05:00', 'Asia/Kolkata'));
        $this->artisan('ira:flush-assignment-telegram-batches')->assertSuccessful();

        $this->assertSame(
            1,
            IraNotification::query()
                ->where('user_id', $assignee->id)
                ->where('notification_type', IraNotificationType::IraAssignmentBatch->value)
                ->where('status', IraNotificationStatus::Sent->value)
                ->count(),
        );
    }

    public function test_cooldown_prevents_spam(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 1],
            ], 200),
        ]);

        $owner = $this->createOwnerWithTelegram('444333222');
        $service = app(IraCommunicationService::class);
        $input = new IraCommunicationInput(
            event: IraNotificationType::RiskAlert,
            insight: new IraOperationalRisk(
                key: 'customer.sla_danger',
                title: 'SLA Breach Risk',
                category: IraRiskCategory::Customer,
                severity: AIRiskLevel::High,
                message: '3 cases risk SLA breach.',
                context: ['overdue' => 2, 'warning' => 1],
            ),
            context: ['dedupe_key' => 'customer.sla_danger'],
        );

        $first = $service->dispatch($input);
        $second = $service->dispatch($input);

        $this->assertCount(1, $first);
        $this->assertSame(IraNotificationStatus::Sent, $first[0]->status);
        $this->assertSame([], $second);
        $this->assertSame(1, IraNotification::query()->where('user_id', $owner->id)->count());
        Http::assertSentCount(1);
    }

    public function test_failed_delivery_is_recorded(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => false,
                'description' => 'Chat not found',
            ], 400),
        ]);

        $owner = $this->createOwnerWithTelegram('111222333');

        $results = app(IraCommunicationService::class)->dispatch(new IraCommunicationInput(
            event: IraNotificationType::RiskAlert,
            insight: new IraOperationalRisk(
                key: 'customer.sla_danger',
                title: 'SLA Breach Risk',
                category: IraRiskCategory::Customer,
                severity: AIRiskLevel::High,
                message: '3 cases risk SLA breach.',
                context: ['overdue' => 2, 'warning' => 1],
            ),
            context: ['dedupe_key' => 'customer.sla_danger'],
        ));

        $this->assertCount(1, $results);
        $this->assertSame(IraNotificationStatus::Failed, $results[0]->status);
        $this->assertSame('Chat not found', $results[0]->error_message);

        $this->assertDatabaseHas('ira_notifications', [
            'user_id' => $owner->id,
            'status' => IraNotificationStatus::Failed->value,
            'error_message' => 'Chat not found',
        ]);
    }

    public function test_superadmin_critical_alerts_still_pass_through_authority_bridge(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 55],
            ], 200),
        ]);

        $owner = $this->createOwnerWithTelegram('123123123');

        $results = app(IraCommunicationService::class)->dispatch(new IraCommunicationInput(
            event: IraNotificationType::IntegrationFailure,
            context: [
                'label' => 'Email',
                'message' => 'SMTP unavailable.',
                'dedupe_key' => 'integration:email',
            ],
        ));

        $this->assertCount(1, $results);
        $this->assertSame(IraNotificationStatus::Sent, $results[0]->status);
        Http::assertSentCount(1);
    }

    public function test_ira_notification_category_mapping_covers_risk_alerts(): void
    {
        $this->assertSame(
            \App\Enums\NotificationCategory::Escalation,
            \App\Services\Notifications\IraNotificationCategoryMapper::toNotificationCategory(
                IraNotificationType::RiskAlert,
            ),
        );
        $this->assertSame(
            \App\Enums\NotificationCategory::DailySummary,
            \App\Services\Notifications\IraNotificationCategoryMapper::toNotificationCategory(
                IraNotificationType::DailyBriefing,
            ),
        );
        $this->assertSame(
            \App\Enums\NotificationCategory::Assignment,
            \App\Services\Notifications\IraNotificationCategoryMapper::toNotificationCategory(
                IraNotificationType::SmartAssignment,
            ),
        );
    }

    public function test_profile_telegram_settings_can_be_updated(): void
    {
        $user = User::factory()->create([
            'telegram_chat_id' => null,
            'telegram_notifications_enabled' => false,
        ]);

        $this->actingAs($user)
            ->patch(route('profile.telegram.update'), [
                'telegram_chat_id' => '999888777',
                'telegram_notifications_enabled' => '1',
            ])
            ->assertRedirect(route('profile.edit'));

        $user->refresh();

        $this->assertSame('999888777', $user->telegram_chat_id);
        $this->assertTrue($user->telegram_notifications_enabled);
    }

    private function createOwnerWithTelegram(string $chatId, string $name = 'Owner User'): User
    {
        $owner = User::factory()->create([
            'name' => $name,
            'first_name' => explode(' ', $name)[0],
            'last_name' => explode(' ', $name)[1] ?? '',
            'telegram_chat_id' => $chatId,
            'telegram_notifications_enabled' => true,
            'is_active' => true,
        ]);
        $owner->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        return $owner;
    }

    private function createSupportAgent(string $name): User
    {
        $agent = User::factory()->create([
            'name' => $name,
            'is_active' => true,
            'availability_status' => TeamAvailabilityStatus::Available,
        ]);
        $agent->assignRole(RolePermissionSeeder::ROLE_SUPPORT_SPECIALIST);

        return $agent;
    }

    private function createIncidentFor(User $agent, string $orderId): Incident
    {
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => 'SN-'.$orderId,
            'product_name' => 'MFS 110 E3',
            'device_model' => 'MFS 110 E3',
            'transaction_id' => null,
            'customer_name' => 'Briefing Customer',
            'customer_email' => 'briefing@example.com',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Internal,
            'title' => 'Telegram briefing case',
            'description' => 'Telegram briefing case.',
            'status' => IncidentStatus::Open,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
            'assigned_to_user_id' => $agent->id,
        ]);
    }

    /**
     * @return array{0: Incident, 1: SupportAppointment}
     */
    private function createAssignmentFixtures(User $assignee): array
    {
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD-TG-ASSIGN',
            'serial_number' => 'SN-RD-TG-ASSIGN',
            'product_name' => 'FM220 Device',
            'device_model' => 'FM220 Device',
            'transaction_id' => null,
            'customer_name' => 'Test Customer',
            'customer_email' => 'test@example.com',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-TG-001',
            'category' => 'General',
            'source' => IncidentSource::Internal,
            'title' => 'Telegram assignment case',
            'description' => 'Telegram assignment case.',
            'status' => IncidentStatus::Open,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
            'assigned_to_user_id' => $assignee->id,
        ]);

        $appointment = SupportAppointment::query()->create([
            'incident_id' => $incident->id,
            'preferred_date' => now()->addDay()->toDateString(),
            'preferred_time_slot' => SupportAppointmentTimeSlot::Morning,
            'phone_number' => '9876543210',
        ]);

        return [$incident, $appointment];
    }
}
