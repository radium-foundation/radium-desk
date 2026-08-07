<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Collection;
use Tests\TestCase;

class SchedulerHardeningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Schedule events are registered via Artisan::starting (Laravel withSchedule).
        $this->artisan('schedule:list')->assertSuccessful();
    }

    public function test_critical_every_minute_jobs_use_short_overlap_and_background_where_required(): void
    {
        $events = collect(app(Schedule::class)->events());

        $queueWork = $this->findEvent($events, 'queue:work');
        $this->assertTrue($queueWork->runInBackground);
        $this->assertSame(2, $queueWork->expiresAt);
        $this->assertTrue($queueWork->withoutOverlapping);

        $automationPending = $this->findEvent($events, 'service-cases:process-automation-pending');
        $this->assertTrue($automationPending->runInBackground);
        $this->assertSame(2, $automationPending->expiresAt);
        $this->assertStringContainsString('--limit=25', (string) $automationPending->command);

        $outbox = $this->findEvent($events, 'outbox:process');
        $this->assertTrue($outbox->runInBackground);
        $this->assertSame(2, $outbox->expiresAt);
        $this->assertStringContainsString('--limit=50', (string) $outbox->command);

        $gmail = $this->findEvent($events, 'inbound-email:sync-gmail');
        $this->assertTrue($gmail->runInBackground);
        $this->assertSame(10, $gmail->expiresAt);

        $heartbeat = $this->findEvent($events, 'operations:scheduler-heartbeat');
        $this->assertFalse($heartbeat->runInBackground);
        $this->assertSame(2, $heartbeat->expiresAt);

        $presence = $this->findEvent($events, 'presence:process-timeouts');
        $this->assertFalse($presence->runInBackground);
        $this->assertSame(2, $presence->expiresAt);

        $radiumbox = $this->findEvent($events, 'radiumbox:recover-sync');
        $this->assertFalse($radiumbox->runInBackground);
        $this->assertLessThan(1440, (int) $radiumbox->expiresAt);
    }

    public function test_heavy_notification_and_recovery_commands_run_in_background(): void
    {
        $events = collect(app(Schedule::class)->events());

        foreach ([
            'team-telegram:send-daily-briefings',
            'team-telegram:send-slot-reminders',
            'team-telegram:send-appointment-reminders',
            'cashfree:auto-recover-missing',
            'missing-serial:process',
            'watchdog:send-critical-alerts',
            'ira:send-daily-briefing',
        ] as $needle) {
            $event = $this->findEvent($events, $needle);
            $this->assertTrue($event->runInBackground, "Expected background: {$needle}");
            $this->assertTrue($event->withoutOverlapping, "Expected overlap lock: {$needle}");
            $this->assertNotNull($event->expiresAt);
            $this->assertLessThan(1440, (int) $event->expiresAt, "Expected short overlap TTL: {$needle}");
        }
    }

    public function test_automation_snapshot_uses_light_tick_and_fifteen_minute_reconcile(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(function (Event $event): bool {
                $haystack = (string) ($event->command ?? '').(string) $event->getSummaryForDisplay();

                return str_contains($haystack, 'automation:snapshot');
            })
            ->values();

        $this->assertCount(2, $events, 'Expected light tick + --reconcile schedule entries');

        $light = $events->first(
            fn (Event $event): bool => ! str_contains((string) ($event->command ?? ''), '--reconcile'),
        );
        $reconcile = $events->first(
            fn (Event $event): bool => str_contains((string) ($event->command ?? ''), '--reconcile'),
        );

        $this->assertNotNull($light);
        $this->assertNotNull($reconcile);
        $this->assertTrue($light->runInBackground);
        $this->assertTrue($reconcile->runInBackground);
        $this->assertTrue($light->withoutOverlapping);
        $this->assertTrue($reconcile->withoutOverlapping);
        $this->assertSame('* * * * *', $light->getExpression());
        $this->assertSame('*/15 * * * *', $reconcile->getExpression());
        $this->assertLessThan(1440, (int) $light->expiresAt);
        $this->assertLessThan(1440, (int) $reconcile->expiresAt);
    }

    public function test_nightly_attendance_reconciliation_remains_registered(): void
    {
        $events = collect(app(Schedule::class)->events());

        $nightly = $events->first(
            fn ($event): bool => $event->description === 'attendance:reconcile-days-nightly',
        );

        $this->assertNotNull($nightly);
        $this->assertTrue($nightly->runInBackground);
        $this->assertSame('0 1 * * *', $nightly->getExpression());
    }

    /**
     * @param  Collection<int, Event>  $events
     */
    private function findEvent($events, string $needle): Event
    {
        $event = $events->first(function (Event $event) use ($needle): bool {
            $haystacks = [
                (string) ($event->command ?? ''),
                (string) ($event->description ?? ''),
                (string) $event->getSummaryForDisplay(),
            ];

            foreach ($haystacks as $haystack) {
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
