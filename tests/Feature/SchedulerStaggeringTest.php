<?php

namespace Tests\Feature;

use Cron\CronExpression;
use DateTimeImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Guards minute-offset staggering that spreads clock-aligned CPU peaks
 * without changing command cadence, args, mutex TTLs, or overnight enablement.
 */
class SchedulerStaggeringTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('schedule:list')->assertSuccessful();
    }

    public function test_warm_runs_every_five_minutes_at_minute_one_mod_five(): void
    {
        $this->assertEventDueMinutes(
            'platform:snapshots:warm',
            '1-59/5 * * * *',
            [1, 6, 11, 16, 21, 26, 31, 36, 41, 46, 51, 56],
        );
    }

    public function test_watchdog_runs_every_five_minutes_at_minute_three_mod_five(): void
    {
        $this->assertEventDueMinutes(
            'watchdog:send-critical-alerts',
            '3-59/5 * * * *',
            [3, 8, 13, 18, 23, 28, 33, 38, 43, 48, 53, 58],
        );
    }

    public function test_metrics_runs_every_five_minutes_at_minute_four_mod_five(): void
    {
        $this->assertEventDueMinutes(
            'infrastructure:metrics:collect',
            '4-59/5 * * * *',
            [4, 9, 14, 19, 24, 29, 34, 39, 44, 49, 54, 59],
        );
    }

    public function test_appointment_reminders_run_every_five_minutes_at_minute_two_mod_five(): void
    {
        $this->assertEventDueMinutes(
            'team-telegram:send-appointment-reminders',
            '2-59/5 * * * *',
            [2, 7, 12, 17, 22, 27, 32, 37, 42, 47, 52, 57],
        );
    }

    public function test_recover_sync_remains_every_fifteen_minutes_on_quarter_hour(): void
    {
        $this->assertEventDueMinutes(
            'radiumbox:recover-sync',
            '*/15 * * * *',
            [0, 15, 30, 45],
        );
    }

    public function test_missing_serial_runs_at_five_twenty_thirty_five_fifty(): void
    {
        $this->assertEventDueMinutes(
            'missing-serial:process',
            '5-59/15 * * * *',
            [5, 20, 35, 50],
        );
    }

    public function test_cashfree_auto_recover_runs_at_seven_twenty_two_thirty_seven_fifty_two(): void
    {
        $this->assertEventDueMinutes(
            'cashfree:auto-recover-missing',
            '7-59/15 * * * *',
            [7, 22, 37, 52],
        );
    }

    public function test_automation_reconcile_runs_at_nine_twenty_four_thirty_nine_fifty_four(): void
    {
        $events = collect(app(Schedule::class)->events());
        $reconcile = $events->first(
            fn (Event $event): bool => str_contains((string) ($event->command ?? ''), 'automation:snapshot')
                && str_contains((string) ($event->command ?? ''), '--reconcile'),
        );

        $this->assertNotNull($reconcile);
        $this->assertSame('9-59/15 * * * *', $reconcile->getExpression());
        $this->assertSame([9, 24, 39, 54], $this->dueMinutes($reconcile->getExpression()));
        $this->assertSame(20, (int) $reconcile->expiresAt);
        $this->assertTrue($reconcile->runInBackground);
    }

    public function test_light_tick_remains_every_minute_unchanged(): void
    {
        $lightTick = $this->findEvent(collect(app(Schedule::class)->events()), 'schedule:light-tick');

        $this->assertSame('* * * * *', $lightTick->getExpression());
        $this->assertFalse($lightTick->runInBackground);
        $this->assertTrue($lightTick->withoutOverlapping);
        $this->assertLessThan(1440, (int) $lightTick->expiresAt);
        $this->assertSame(range(0, 59), $this->dueMinutes($lightTick->getExpression()));
    }

    public function test_stagger_preserves_without_overlapping_and_mutex_ttls(): void
    {
        $events = collect(app(Schedule::class)->events());

        $cases = [
            'platform:snapshots:warm' => 5,
            'watchdog:send-critical-alerts' => 1440,
            'infrastructure:metrics:collect' => 5,
            'team-telegram:send-appointment-reminders' => 5,
            'radiumbox:recover-sync' => 15,
            'missing-serial:process' => 15,
            'cashfree:auto-recover-missing' => 15,
        ];

        foreach ($cases as $needle => $expiresAt) {
            $event = $this->findEvent($events, $needle);
            $this->assertTrue($event->withoutOverlapping, "{$needle} must keep withoutOverlapping");
            $this->assertSame($expiresAt, (int) $event->expiresAt, "{$needle} mutex TTL must stay {$expiresAt}");
        }
    }

    public function test_missing_serial_runs_in_background(): void
    {
        $event = $this->findEvent(collect(app(Schedule::class)->events()), 'missing-serial:process');

        $this->assertTrue($event->runInBackground);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame('5-59/15 * * * *', $event->getExpression());
        $this->assertSame(15, (int) $event->expiresAt);
    }

    /**
     * @param  list<int>  $expectedMinutes
     */
    private function assertEventDueMinutes(string $needle, string $expression, array $expectedMinutes): void
    {
        $event = $this->findEvent(collect(app(Schedule::class)->events()), $needle);

        $this->assertSame($expression, $event->getExpression());
        $this->assertSame($expectedMinutes, $this->dueMinutes($event->getExpression()));
        $this->assertCount(count($expectedMinutes), $this->dueMinutes($event->getExpression()));
    }

    /**
     * @return list<int>
     */
    private function dueMinutes(string $expression): array
    {
        $cron = new CronExpression($expression);
        $minutes = [];

        for ($minute = 0; $minute < 60; $minute++) {
            $at = new DateTimeImmutable(sprintf('2026-08-09 04:%02d:00', $minute));
            if ($cron->isDue($at)) {
                $minutes[] = $minute;
            }
        }

        return $minutes;
    }

    /**
     * @param  Collection<int, Event>  $events
     */
    private function findEvent(Collection $events, string $needle): Event
    {
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
