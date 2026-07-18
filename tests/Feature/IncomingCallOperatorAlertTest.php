<?php

namespace Tests\Feature;

use App\Enums\BonvoiceCallAlertType;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Events\Dashboard\NotificationCreated;
use App\Events\Dashboard\OperatorAlertRaised;
use App\Models\Incident;
use App\Models\Order;
use App\Models\SystemSetting;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Notifications\IncomingCallAssistNotification;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IncomingCallOperatorAlertTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        Cache::flush();
        Queue::fake();

        config([
            'bonvoice.verify_signature' => false,
            'bonvoice.webhook_token' => 'test-bonvoice-token',
            'bonvoice.account_id' => 'acct-001',
            'operator_alerts.enabled' => true,
            'operator_alerts.desktop_enabled' => true,
            'operator_alerts.sound_enabled' => true,
            'services.telegram.bot_token' => 'test-bot-token',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-07-18 10:15:00', 'Asia/Kolkata'));
        $this->enableTelegramChannel();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_incoming_call_uses_dispatcher_for_history_reverb_and_telegram(): void
    {
        Notification::fake();
        Event::fake([OperatorAlertRaised::class, NotificationCreated::class]);
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 9001],
            ], 200),
        ]);

        $agent = $this->createTelegramAgentWithKnownCustomer();

        $this->postJson('/api/webhooks/bonvoice', $this->inboundCallPayload(
            callId: 'call-oa-known-001',
            status: 'Ringing',
            eventId: 'evt-oa-known-001',
        ))->assertOk();

        Notification::assertSentToTimes($agent, IncomingCallAssistNotification::class, 1);
        Event::assertDispatched(OperatorAlertRaised::class, function (OperatorAlertRaised $event) use ($agent): bool {
            $payload = $event->broadcastWith();

            return $event->recipient->is($agent)
                && $payload['deduplication_key'] === 'ivr:call:call-oa-known-001'
                && $payload['severity'] === 'critical'
                && $payload['desktop_popup'] === true
                && $payload['category'] === 'ivr';
        });
        Event::assertDispatchedTimes(OperatorAlertRaised::class, 1);

        Http::assertSentCount(1);
        Http::assertSent(function ($request) use ($agent): bool {
            $body = (string) $request->body();

            return str_contains($request->url(), 'sendMessage')
                && $request['chat_id'] === (string) $agent->telegram_chat_id
                && str_contains($body, 'Incoming Call')
                && str_contains($body, '******3210')
                && ! str_contains($body, '9876543210');
        });
    }

    public function test_flag_disabled_keeps_legacy_notify_path_without_operator_alert_or_telegram(): void
    {
        config(['operator_alerts.enabled' => false]);

        Notification::fake();
        Event::fake([OperatorAlertRaised::class]);
        Http::fake();

        $agent = $this->createTelegramAgentWithKnownCustomer();

        $this->postJson('/api/webhooks/bonvoice', $this->inboundCallPayload(
            callId: 'call-oa-legacy-001',
            status: 'Ringing',
            eventId: 'evt-oa-legacy-001',
        ))->assertOk();

        Notification::assertSentTo($agent, IncomingCallAssistNotification::class);
        Event::assertNotDispatched(OperatorAlertRaised::class);
        Http::assertNothingSent();
    }

    public function test_duplicate_lifecycle_still_creates_one_history_one_reverb_one_telegram(): void
    {
        Notification::fake();
        Event::fake([OperatorAlertRaised::class]);
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 9002],
            ], 200),
        ]);

        $agent = $this->createTelegramAgentWithKnownCustomer();

        $this->postJson('/api/webhooks/bonvoice', $this->inboundCallPayload(
            callId: 'call-oa-dup-001',
            status: 'Ringing',
            eventId: 'evt-oa-dup-1',
        ))->assertOk();

        $this->postJson('/api/webhooks/bonvoice', $this->inboundCallPayload(
            callId: 'call-oa-dup-001',
            status: 'COMPLETED',
            eventId: 'evt-oa-dup-2',
        ))->assertOk();

        Notification::assertSentToTimes($agent, IncomingCallAssistNotification::class, 1);
        Event::assertDispatchedTimes(OperatorAlertRaised::class, 1);
        Http::assertSentCount(1);
        $this->assertDatabaseHas('bonvoice_call_alerts', [
            'call_id' => 'call-oa-dup-001',
            'alert_type' => BonvoiceCallAlertType::CustomerFound->value,
        ]);
    }

    public function test_telegram_unavailable_still_persists_history_and_broadcasts_reverb(): void
    {
        config(['services.telegram.bot_token' => '']);

        Notification::fake();
        Event::fake([OperatorAlertRaised::class]);
        Http::fake();

        $agent = $this->createTelegramAgentWithKnownCustomer();

        $this->postJson('/api/webhooks/bonvoice', $this->inboundCallPayload(
            callId: 'call-oa-no-tg-001',
            status: 'Ringing',
            eventId: 'evt-oa-no-tg-001',
        ))->assertOk();

        Notification::assertSentToTimes($agent, IncomingCallAssistNotification::class, 1);
        Event::assertDispatchedTimes(OperatorAlertRaised::class, 1);
        Http::assertNothingSent();
    }

    public function test_history_notification_created_suppresses_desktop_when_operator_alerts_enabled(): void
    {
        Event::fake([OperatorAlertRaised::class, NotificationCreated::class]);
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 9003],
            ], 200),
        ]);

        $this->createTelegramAgentWithKnownCustomer();

        $this->postJson('/api/webhooks/bonvoice', $this->inboundCallPayload(
            callId: 'call-oa-suppress-001',
            status: 'Ringing',
            eventId: 'evt-oa-suppress-001',
        ))->assertOk();

        Event::assertDispatched(OperatorAlertRaised::class);
        Event::assertDispatched(NotificationCreated::class, function (NotificationCreated $event): bool {
            return $event->suppressDesktopNotification === true
                && $event->bellHtml !== ''
                && $event->title !== '';
        });
    }

    private function createTelegramAgentWithKnownCustomer(): User
    {
        $agent = User::factory()->create([
            'bonvoice_extension' => '1800123456',
            'telegram_chat_id' => '555666777',
            'telegram_notifications_enabled' => true,
            'is_active' => true,
        ]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        TeamMemberWorkSchedule::query()->create([
            'user_id' => $agent->id,
            'work_start_time' => '09:00:00',
            'work_end_time' => '18:00:00',
            'lunch_start_time' => '13:30:00',
            'lunch_end_time' => '14:00:00',
            'short_break_count' => 2,
            'short_break_minutes' => 10,
            'weekly_off_days' => [Carbon::SUNDAY],
        ]);

        $order = Order::query()->create([
            'order_id' => 'RD3444319',
            'serial_number' => null,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Known Customer',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Open case',
            'description' => 'Operator alert incoming call test.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        return $agent->fresh(['workSchedule']);
    }

    private function enableTelegramChannel(): void
    {
        SystemSetting::query()->updateOrCreate(
            ['key' => 'notifications.telegram.enabled'],
            ['value' => '1'],
        );

        app(\App\Services\SystemSettingsService::class)->forget('notifications.telegram.enabled');
    }

    /**
     * @return array<string, mixed>
     */
    private function inboundCallPayload(
        string $callId,
        string $status,
        string $eventId,
        string $sourceNumber = '9876543210',
        string $destinationNumber = '1800123456',
    ): array {
        return [
            'SourceNumber' => $sourceNumber,
            'DestinationNumber' => $destinationNumber,
            'DisplayNumber' => $destinationNumber,
            'StartTime' => Carbon::parse('2026-07-08T14:32:23')->toIso8601String(),
            'DataSource' => 'IVR',
            'callType' => 'Support',
            'AccountID' => 'acct-001',
            'callID' => $callId,
            'Direction' => 'Inbound',
            'Leg' => 'A',
            'Status' => $status,
            'eventID' => $eventId,
            'callBackParentID' => null,
            'callBackParams' => null,
        ];
    }
}
