<?php

namespace Tests\Feature\Broadcasting;

use App\Broadcasting\ResilientRealtimeBroadcaster;
use App\Events\Dashboard\DashboardKpisUpdated;
use App\Events\Dashboard\IncomingCallReceived;
use App\Events\Dashboard\NotificationCreated;
use App\Infrastructure\Queue\QueueRouting;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\SystemSettingsSeeder;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class QueuedRealtimeBroadcastIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
        $this->seed(SystemSettingsSeeder::class);

        config([
            'broadcasting.default' => 'log',
            'queue.default' => 'database',
            'queue.connections.database.after_commit' => false,
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_dashboard_events_implement_should_broadcast_not_now(): void
    {
        $this->assertInstanceOf(ShouldBroadcast::class, new DashboardKpisUpdated(
            recipient: User::factory()->make(),
            kpiStripHtml: '<div></div>',
        ));
        $this->assertNotInstanceOf(ShouldBroadcastNow::class, new DashboardKpisUpdated(
            recipient: User::factory()->make(),
            kpiStripHtml: '<div></div>',
        ));
    }

    public function test_incoming_call_event_uses_critical_queue(): void
    {
        $event = new IncomingCallReceived(User::factory()->make(), [
            'call_id' => 'c-1',
            'customer_name' => null,
            'mobile_number' => null,
            'call_status' => 'ringing',
            'assigned_operator' => null,
            'received_at' => now()->toIso8601String(),
            'incident_id' => null,
            'action_url' => null,
        ]);

        $this->assertSame(QueueRouting::critical(), $event->broadcastQueue());
    }

    public function test_notification_created_event_uses_notifications_queue(): void
    {
        $event = new NotificationCreated(
            recipient: User::factory()->make(),
            notificationId: 'n-1',
            title: 't',
            message: 'm',
            url: '/',
            unreadCount: 1,
            bellHtml: '',
        );

        $this->assertSame(QueueRouting::notifications(), $event->broadcastQueue());
    }

    public function test_resilient_broadcaster_enqueues_dashboard_notification_events(): void
    {
        $recipient = User::factory()->create();

        app(ResilientRealtimeBroadcaster::class)->dispatch(new NotificationCreated(
            recipient: $recipient,
            notificationId: 'n-queued-1',
            title: 'Call',
            message: 'Ringing',
            url: '/dashboard',
            unreadCount: 1,
            bellHtml: '<span>1</span>',
        ));

        $this->assertDatabaseHas('jobs', [
            'queue' => QueueRouting::notifications(),
        ]);
    }

    public function test_resilient_broadcaster_survives_dispatch_failures_without_throwing(): void
    {
        Log::spy();

        Broadcast::shouldReceive('event')->andThrow(new \RuntimeException('queue unavailable'));

        $recipient = User::factory()->create();
        $recipient->notifications()->create([
            'id' => 'fail-open-notif-1',
            'type' => 'Tests\\Support\\QueuedBroadcastTestNotification',
            'data' => [
                'title' => 'Persisted',
                'message' => 'Unchanged',
                'url' => '/dashboard',
            ],
        ]);

        $jobsBefore = DB::table('jobs')->count();

        $threw = false;

        try {
            app(ResilientRealtimeBroadcaster::class)->dispatch(new NotificationCreated(
                recipient: $recipient,
                notificationId: 'n-fail-1',
                title: 't',
                message: 'm',
                url: '/',
                unreadCount: 1,
                bellHtml: '',
            ));
        } catch (\Throwable) {
            $threw = true;
        }

        $this->assertFalse($threw, 'ResilientRealtimeBroadcaster must not propagate enqueue failures.');
        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame($jobsBefore, DB::table('jobs')->count());
        Log::shouldHaveReceived('warning')
            ->once()
            ->with(
                'realtime.broadcast.dispatch_failed',
                Mockery::on(fn (array $context): bool => ($context['event'] ?? '') === NotificationCreated::class
                    && ($context['exception'] ?? '') === \RuntimeException::class
                    && str_contains((string) ($context['message'] ?? ''), 'queue unavailable')),
            );
    }

    public function test_incoming_call_event_enqueues_on_critical_queue(): void
    {
        app(ResilientRealtimeBroadcaster::class)->dispatch(new IncomingCallReceived(
            recipient: User::factory()->create(),
            call: [
                'call_id' => 'hc-1',
                'customer_name' => null,
                'mobile_number' => '9000000000',
                'call_status' => 'ringing',
                'assigned_operator' => null,
                'received_at' => now()->toIso8601String(),
                'incident_id' => null,
                'action_url' => '/dashboard',
            ],
        ));

        $this->assertDatabaseHas('jobs', [
            'queue' => QueueRouting::critical(),
        ]);
    }
}
