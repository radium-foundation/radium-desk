<?php

namespace Tests\Unit\HybridRealtime;

use App\Data\OperatorAlert;
use App\Enums\AlertSeverity;
use App\Enums\NotificationCategory;
use App\Enums\NotificationPriority;
use App\Events\Dashboard\IncomingCallReceived;
use App\Events\Dashboard\RealtimeNotificationDelivered;
use App\Enums\BonvoiceCallAlertType;
use App\Models\BonvoiceCallAlert;
use App\Models\BonvoiceCallEvent;
use App\Models\User;
use App\Services\HybridRealtime\HybridRealtimeNotificationBroadcaster;
use App\Services\SystemSettingsService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SystemSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class HybridRealtimeNotificationBroadcasterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SystemSettingsSeeder::class);
    }

    public function test_incoming_call_broadcast_is_gated_by_feature_flag(): void
    {
        Event::fake([IncomingCallReceived::class]);

        $agent = User::factory()->create(['is_active' => true]);
        $event = BonvoiceCallEvent::query()->create([
            'call_id' => 'call-gate-001',
            'leg' => 'a',
            'customer_phone' => '9876543210',
            'direction' => 'inbound',
            'status' => 'Ringing',
            'event_id' => 'evt-gate-001',
            'payload' => [],
        ]);
        $alert = BonvoiceCallAlert::query()->create([
            'bonvoice_call_event_id' => $event->id,
            'call_id' => 'call-gate-001',
            'user_id' => $agent->id,
            'alert_type' => BonvoiceCallAlertType::CustomerFound,
            'customer_phone' => '9876543210',
            'notified_at' => now(),
        ]);

        app(HybridRealtimeNotificationBroadcaster::class)->broadcastIncomingCall($agent, $alert);

        Event::assertNotDispatched(IncomingCallReceived::class);

        app(SystemSettingsService::class)->set('hybrid_realtime.incoming_calls', true);

        app(HybridRealtimeNotificationBroadcaster::class)->broadcastIncomingCall($agent, $alert);

        Event::assertDispatched(IncomingCallReceived::class);
    }

    public function test_operator_alert_builds_priority_payload(): void
    {
        $broadcaster = app(HybridRealtimeNotificationBroadcaster::class);

        $notification = $broadcaster->buildFromOperatorAlert(new OperatorAlert(
            title: 'Incoming Call',
            message: 'Customer on line',
            severity: AlertSeverity::Critical,
            category: NotificationCategory::Ivr,
            icon: 'bi-telephone-inbound',
            actionUrl: '/dashboard',
            entityType: 'call',
            entityId: 'call-123',
            deduplicationKey: 'ivr:call:call-123',
            playSound: true,
            desktopPopup: true,
        ));

        $this->assertSame(NotificationPriority::Critical, $notification->priority);
        $this->assertTrue($notification->requiresAcknowledgement);
        $this->assertSame('operator_alert', $notification->type);
    }

    public function test_realtime_notification_broadcast_is_gated_by_desktop_feature(): void
    {
        Event::fake([RealtimeNotificationDelivered::class]);

        $user = User::factory()->create(['is_active' => true]);
        $broadcaster = app(HybridRealtimeNotificationBroadcaster::class);
        $notification = $broadcaster->buildFromOperatorAlert(new OperatorAlert(
            title: 'Test',
            message: 'Message',
            severity: AlertSeverity::High,
            category: NotificationCategory::Assignment,
            icon: 'bi-bell',
            actionUrl: '/dashboard',
            entityType: null,
            entityId: null,
            deduplicationKey: 'test:1',
        ));

        $broadcaster->broadcastRealtimeNotification($user, $notification);

        Event::assertNotDispatched(RealtimeNotificationDelivered::class);

        app(SystemSettingsService::class)->set('hybrid_realtime.operator_alerts', true);

        $broadcaster->broadcastRealtimeNotification($user, $notification);

        Event::assertDispatched(RealtimeNotificationDelivered::class);
    }
}
