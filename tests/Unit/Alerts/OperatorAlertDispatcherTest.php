<?php

namespace Tests\Unit\Alerts;

use App\Data\OperatorAlert;
use App\Enums\AlertSeverity;
use App\Enums\NotificationCategory;
use App\Events\Dashboard\OperatorAlertRaised;
use App\Models\SystemSetting;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Services\Alerts\OperatorAlertCatalog;
use App\Services\Alerts\OperatorAlertDispatcher;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class OperatorAlertDispatcherTest extends TestCase
{
    use RefreshDatabase;

    private OperatorAlertCatalog $catalog;

    private OperatorAlertDispatcher $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->catalog = app(OperatorAlertCatalog::class);
        $this->dispatcher = app(OperatorAlertDispatcher::class);

        Cache::flush();

        config([
            'operator_alerts.enabled' => true,
            'operator_alerts.desktop_enabled' => true,
            'operator_alerts.sound_enabled' => true,
            'services.telegram.bot_token' => 'test-bot-token',
        ]);
    }

    public function test_dispatch_is_no_op_when_feature_flag_disabled(): void
    {
        config(['operator_alerts.enabled' => false]);

        Event::fake([OperatorAlertRaised::class]);

        $recipient = User::factory()->create(['is_active' => true]);
        $alert = $this->sampleAlert();

        $result = $this->dispatcher->dispatch($alert, $recipient);

        $this->assertFalse($result->dispatched);
        $this->assertSame('operator_alerts_disabled', $result->reason);
        Event::assertNotDispatched(OperatorAlertRaised::class);
    }

    public function test_dispatch_broadcasts_operator_alert_raised_to_explicit_recipient(): void
    {
        Event::fake([OperatorAlertRaised::class]);

        $recipient = User::factory()->create(['is_active' => true]);
        $alert = $this->sampleAlert();

        $result = $this->dispatcher->dispatch($alert, $recipient);

        $this->assertTrue($result->dispatched);
        $this->assertSame([$recipient->id], $result->recipientIds);
        $this->assertFalse($result->historyPersisted);

        Event::assertDispatched(OperatorAlertRaised::class, function (OperatorAlertRaised $event) use ($recipient, $alert): bool {
            return $event->recipient->is($recipient)
                && $event->alert->title === $alert->title
                && $event->alert->deduplicationKey === $alert->deduplicationKey
                && $event->broadcastAs() === 'OperatorAlertRaised'
                && $event->broadcastOn()[0]->name === 'private-notifications.'.$recipient->id;
        });
    }

    public function test_dispatch_resolves_recipients_from_category_context(): void
    {
        Event::fake([OperatorAlertRaised::class]);

        $superadmin = User::factory()->create(['is_active' => true]);
        $superadmin->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $alert = $this->catalog->make(
            eventType: OperatorAlertCatalog::EVENT_HIGH_PRIORITY_SERVICE_CASE,
            title: 'High Priority',
            message: 'Flagged high priority',
            actionUrl: '/incidents/1',
            entityType: 'incident',
            entityId: 1,
        );

        $result = $this->dispatcher->dispatch($alert);

        $this->assertTrue($result->dispatched);
        $this->assertContains($superadmin->id, $result->recipientIds);
        Event::assertDispatched(OperatorAlertRaised::class);
    }

    public function test_dispatch_skips_inactive_recipients(): void
    {
        Event::fake([OperatorAlertRaised::class]);

        $inactive = User::factory()->create(['is_active' => false]);
        $alert = $this->sampleAlert();

        $result = $this->dispatcher->dispatch($alert, $inactive);

        $this->assertFalse($result->dispatched);
        $this->assertSame('no_recipients', $result->reason);
        Event::assertNotDispatched(OperatorAlertRaised::class);
    }

    public function test_optional_history_persistence_uses_existing_notification_pipeline(): void
    {
        Event::fake([OperatorAlertRaised::class]);
        Notification::fake();

        $recipient = User::factory()->create(['is_active' => true]);
        $alert = $this->sampleAlert();
        $history = new class extends \Illuminate\Notifications\Notification
        {
            public function via(object $notifiable): array
            {
                return ['database'];
            }

            /**
             * @return array<string, mixed>
             */
            public function toArray(object $notifiable): array
            {
                return [
                    'title' => 'History',
                    'message' => 'Persisted via dispatcher',
                    'url' => '/notifications',
                ];
            }
        };

        $result = $this->dispatcher->dispatch(
            alert: $alert,
            recipients: $recipient,
            historyNotification: $history,
            persistHistory: true,
        );

        $this->assertTrue($result->dispatched);
        $this->assertTrue($result->historyPersisted);
        Notification::assertSentTo($recipient, $history::class);
        Event::assertDispatched(OperatorAlertRaised::class);
    }

    public function test_desktop_and_sound_flags_are_honoured_on_broadcast_payload(): void
    {
        config([
            'operator_alerts.desktop_enabled' => false,
            'operator_alerts.sound_enabled' => false,
        ]);

        Event::fake([OperatorAlertRaised::class]);

        $recipient = User::factory()->create(['is_active' => true]);
        $alert = $this->catalog->make(
            eventType: OperatorAlertCatalog::EVENT_INCOMING_CALL,
            title: 'Incoming Call',
            message: 'Ringing',
            actionUrl: '/dashboard',
            entityType: 'call',
            entityId: 'c1',
        );

        $this->dispatcher->dispatch($alert, $recipient);

        Event::assertDispatched(OperatorAlertRaised::class, function (OperatorAlertRaised $event): bool {
            $payload = $event->broadcastWith();

            return $payload['desktop_popup'] === false
                && $payload['play_sound'] === false
                && $payload['severity'] === AlertSeverity::Critical->value;
        });
    }

    public function test_duplicate_deduplication_key_is_suppressed(): void
    {
        Event::fake([OperatorAlertRaised::class]);

        $recipient = User::factory()->create(['is_active' => true]);
        $alert = $this->sampleAlert('dup:incident:9');

        $first = $this->dispatcher->dispatch($alert, $recipient);
        $second = $this->dispatcher->dispatch($alert, $recipient);

        $this->assertTrue($first->dispatched);
        $this->assertFalse($second->dispatched);
        $this->assertSame('duplicate:dup:incident:9', $second->reason);
        Event::assertDispatchedTimes(OperatorAlertRaised::class, 1);
    }

    public function test_telegram_is_sent_once_to_intended_recipient_when_authority_allows(): void
    {
        Event::fake([OperatorAlertRaised::class]);
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 501],
            ], 200),
        ]);

        Carbon::setTestNow(Carbon::parse('2026-07-18 10:00:00', 'Asia/Kolkata'));
        $this->enableTelegramChannel();

        $recipient = User::factory()->create([
            'is_active' => true,
            'telegram_chat_id' => '777888999',
            'telegram_notifications_enabled' => true,
        ]);
        $recipient->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $this->createWorkSchedule($recipient);

        $alert = $this->catalog->make(
            eventType: OperatorAlertCatalog::EVENT_INCOMING_CALL,
            title: '📞 Incoming Call',
            message: 'Customer Found',
            actionUrl: '/incidents/1',
            entityType: 'call',
            entityId: 'call-1',
            deduplicationKey: 'ivr:call:call-1',
        );

        $result = $this->dispatcher->dispatch(
            alert: $alert,
            recipients: $recipient,
            deliverTelegram: true,
            telegramMessage: "📞 Incoming Call\n\nOpen in Radium Desk\n/incidents/1",
        );

        $this->assertTrue($result->dispatched);
        $this->assertTrue($result->telegramSent());
        $this->assertSame([$recipient->id], $result->telegramRecipientIds);

        Http::assertSentCount(1);
        Http::assertSent(function ($request) use ($recipient): bool {
            return str_contains($request->url(), 'sendMessage')
                && $request['chat_id'] === (string) $recipient->telegram_chat_id;
        });

        Carbon::setTestNow();
    }

    public function test_telegram_failure_does_not_block_reverb_broadcast(): void
    {
        Event::fake([OperatorAlertRaised::class]);
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => false,
                'description' => 'Forbidden',
            ], 403),
        ]);

        Carbon::setTestNow(Carbon::parse('2026-07-18 10:00:00', 'Asia/Kolkata'));
        $this->enableTelegramChannel();

        $recipient = User::factory()->create([
            'is_active' => true,
            'telegram_chat_id' => '777888999',
            'telegram_notifications_enabled' => true,
        ]);
        $recipient->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $this->createWorkSchedule($recipient);

        $alert = $this->sampleAlert('ivr:call:call-fail');

        $result = $this->dispatcher->dispatch(
            alert: $alert,
            recipients: $recipient,
            deliverTelegram: true,
            telegramMessage: 'Incoming call',
        );

        $this->assertTrue($result->dispatched);
        $this->assertFalse($result->telegramSent());
        Event::assertDispatched(OperatorAlertRaised::class);

        Carbon::setTestNow();
    }

    private function sampleAlert(string $deduplicationKey = 'test:incident:1'): OperatorAlert
    {
        return new OperatorAlert(
            title: 'Test Alert',
            message: 'Something needs attention',
            severity: AlertSeverity::Medium,
            category: NotificationCategory::Assignment,
            icon: 'bi-bell',
            actionUrl: '/incidents/1',
            entityType: 'incident',
            entityId: 1,
            deduplicationKey: $deduplicationKey,
            desktopPopup: true,
            playSound: false,
        );
    }

    private function enableTelegramChannel(): void
    {
        SystemSetting::query()->updateOrCreate(
            ['key' => 'notifications.telegram.enabled'],
            ['value' => '1'],
        );

        app(\App\Services\SystemSettingsService::class)->forget('notifications.telegram.enabled');
    }

    private function createWorkSchedule(User $user): void
    {
        TeamMemberWorkSchedule::query()->create([
            'user_id' => $user->id,
            'work_start_time' => '09:00:00',
            'work_end_time' => '18:00:00',
            'lunch_start_time' => '13:30:00',
            'lunch_end_time' => '14:00:00',
            'short_break_count' => 2,
            'short_break_minutes' => 10,
            'weekly_off_days' => [Carbon::SUNDAY],
        ]);
    }
}

