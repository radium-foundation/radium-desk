<?php

namespace Tests\Feature\Platform;

use App\Services\Platform\PlatformHealthCache;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SchedulerHeartbeatScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_heartbeat_releases_host_flock_before_background_gmail_and_records_last_run(): void
    {
        Cache::flush();

        // Ensure Artisan::starting callbacks from withSchedule() are applied even when
        // earlier tests in the same process already booted a console application.
        $this->artisan('schedule:list')->assertSuccessful();

        $events = collect(app(Schedule::class)->events());

        $heartbeat = $events->first(
            fn ($event): bool => $event->description === 'operations:scheduler-heartbeat',
        );
        $gmail = $events->first(
            fn ($event): bool => is_string($event->command)
                && str_contains($event->command, 'inbound-email:sync-gmail'),
        );

        $this->assertNotNull($heartbeat);
        $this->assertSame($events->first(), $heartbeat);
        $this->assertFalse($heartbeat->withoutOverlapping);
        $this->assertSame('* * * * *', $heartbeat->getExpression());

        $this->assertNotNull($gmail);
        $this->assertTrue($gmail->runInBackground);
        $this->assertTrue($gmail->withoutOverlapping);
        $this->assertSame(10, $gmail->expiresAt);

        $heartbeat->run(app());

        $this->assertNotNull(PlatformHealthCache::schedulerLastRunAt());
        $this->assertLessThanOrEqual(5, PlatformHealthCache::schedulerLastRunAt()->diffInSeconds(now()));
    }
}
