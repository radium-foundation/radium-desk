<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Guards that scheduled jobs evaluate cron expressions in Asia/Kolkata via
 * config('app.schedule_timezone'), independent of runtime app.timezone changes.
 */
class SchedulerTimezoneTest extends TestCase
{
    private const SCHEDULER_TIMEZONE = 'Asia/Kolkata';

    /** @var list<string> */
    private const TELEGRAM_EVENT_NEEDLES = [
        'ira:send-daily-briefing',
        'ira:send-ops-digest --period=morning',
        'ira:send-ops-digest --period=evening',
        'ira:send-owner-intelligence --period=morning',
        'ira:send-owner-intelligence --period=evening',
        'ira:send-risk-alerts',
        'watchdog:send-critical-alerts',
        'team-telegram:send-daily-briefings',
        'team-telegram:send-slot-reminders',
        'team-telegram:send-appointment-reminders',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('schedule:list')->assertSuccessful();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_schedule_timezone_config_is_asia_kolkata(): void
    {
        $this->assertSame(self::SCHEDULER_TIMEZONE, config('app.schedule_timezone'));
    }

    public function test_telegram_related_events_use_dedicated_scheduler_timezone(): void
    {
        $events = collect(app(Schedule::class)->events());

        foreach (self::TELEGRAM_EVENT_NEEDLES as $needle) {
            $event = $this->findEvent($events, $needle);

            $this->assertSame(
                self::SCHEDULER_TIMEZONE,
                $event->timezone,
                "Expected {$needle} to use ".self::SCHEDULER_TIMEZONE,
            );
        }
    }

    public function test_daily_briefing_is_due_at_08_00_ist(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-21 08:00:00', self::SCHEDULER_TIMEZONE));

        $event = $this->findEvent(collect(app(Schedule::class)->events()), 'ira:send-daily-briefing');

        $this->assertTrue($event->isDue($this->app));
    }

    public function test_daily_briefing_is_not_due_at_13_30_ist(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-21 13:30:00', self::SCHEDULER_TIMEZONE));

        $event = $this->findEvent(collect(app(Schedule::class)->events()), 'ira:send-daily-briefing');

        $this->assertFalse($event->isDue($this->app));
    }

    public function test_changing_app_timezone_does_not_change_scheduler_timezone_config_or_event_timezone(): void
    {
        config(['app.timezone' => 'UTC']);

        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame(self::SCHEDULER_TIMEZONE, config('app.schedule_timezone'));

        $this->app->forgetInstance(Schedule::class);
        $this->artisan('schedule:list')->assertSuccessful();

        $event = $this->findEvent(
            collect(app(Schedule::class)->events()),
            'ira:send-daily-briefing',
        );

        $this->assertSame(self::SCHEDULER_TIMEZONE, $event->timezone);
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
