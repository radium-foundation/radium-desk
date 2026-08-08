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

    public function test_phase_10_light_tick_replaces_separate_every_minute_light_jobs(): void
    {
        $events = collect(app(Schedule::class)->events());

        $lightTick = $this->findEvent($events, 'schedule:light-tick');
        $this->assertFalse($lightTick->runInBackground);
        $this->assertTrue($lightTick->withoutOverlapping);
        $this->assertSame('* * * * *', $lightTick->getExpression());
        $this->assertLessThan(1440, (int) $lightTick->expiresAt);

        foreach ([
            'service-cases:process-automation-pending',
            'ira:flush-assignment-telegram-batches',
            'outbox:process',
            'presence:process-timeouts',
        ] as $retired) {
            $this->assertNull(
                $this->findEventOrNull($events, $retired),
                "Expected {$retired} to be folded into schedule:light-tick",
            );
        }
    }

    public function test_phase_10_retuned_cadences(): void
    {
        $events = collect(app(Schedule::class)->events());

        $warm = $this->findEvent($events, 'platform:snapshots:warm');
        $this->assertSame('1-59/5 * * * *', $warm->getExpression());
        $this->assertLessThan(1440, (int) $warm->expiresAt);

        $gmail = $this->findEvent($events, 'inbound-email:sync-gmail');
        $this->assertTrue($gmail->runInBackground);
        $this->assertSame(10, $gmail->expiresAt);
        $this->assertSame('*/2 * * * *', $gmail->getExpression());

        $reminders = $this->findEvent($events, 'team-telegram:send-appointment-reminders');
        $this->assertSame('2-59/5 * * * *', $reminders->getExpression());
        $this->assertLessThan(1440, (int) $reminders->expiresAt);

        $cashfree = $this->findEvent($events, 'cashfree:auto-recover-missing');
        $this->assertSame('7-59/15 * * * *', $cashfree->getExpression());
        $this->assertLessThan(1440, (int) $cashfree->expiresAt);
    }

    public function test_heartbeat_queue_gate_and_automation_semantics_preserved(): void
    {
        $events = collect(app(Schedule::class)->events());

        $heartbeat = $this->findEvent($events, 'operations:scheduler-heartbeat');
        $this->assertSame($events->first(), $heartbeat);
        $this->assertFalse($heartbeat->withoutOverlapping);
        $this->assertSame('* * * * *', $heartbeat->getExpression());

        $queueWork = $this->findEvent($events, 'queue:work');
        $this->assertTrue($queueWork->withoutOverlapping);
        $this->assertLessThan(1440, (int) $queueWork->expiresAt);

        $automationEvents = $events
            ->filter(function (Event $event): bool {
                $haystack = (string) ($event->command ?? '').(string) $event->getSummaryForDisplay();

                return str_contains($haystack, 'automation:snapshot');
            })
            ->values();

        $this->assertCount(2, $automationEvents, 'Expected light tick + --reconcile schedule entries');

        $light = $automationEvents->first(
            fn (Event $event): bool => ! str_contains((string) ($event->command ?? ''), '--reconcile'),
        );
        $reconcile = $automationEvents->first(
            fn (Event $event): bool => str_contains((string) ($event->command ?? ''), '--reconcile'),
        );

        $this->assertNotNull($light);
        $this->assertNotNull($reconcile);
        $this->assertTrue($light->runInBackground);
        $this->assertTrue($reconcile->runInBackground);
        $this->assertSame('* * * * *', $light->getExpression());
        $this->assertSame('9-59/15 * * * *', $reconcile->getExpression());
        $this->assertSame(5, (int) $light->expiresAt);
        $this->assertSame(20, (int) $reconcile->expiresAt);
    }

    public function test_nightly_attendance_reconciliation_remains_registered(): void
    {
        $events = collect(app(Schedule::class)->events());

        $nightly = $events->first(
            fn ($event): bool => $event->description === 'attendance:reconcile-days-nightly',
        );

        $this->assertNotNull($nightly);
        $this->assertSame('0 1 * * *', $nightly->getExpression());
    }

    /**
     * @param  Collection<int, Event>  $events
     */
    private function findEvent($events, string $needle): Event
    {
        $event = $this->findEventOrNull($events, $needle);
        $this->assertNotNull($event, "Scheduled event not found: {$needle}");

        return $event;
    }

    /**
     * @param  Collection<int, Event>  $events
     */
    private function findEventOrNull($events, string $needle): ?Event
    {
        return $events->first(function (Event $event) use ($needle): bool {
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
    }
}
