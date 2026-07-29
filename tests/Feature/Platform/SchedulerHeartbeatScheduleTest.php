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

    public function test_heartbeat_is_first_without_overlap_and_records_last_run_before_background_gmail(): void
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

    public function test_hostinger_schedule_run_wrapper_exists_and_drops_cron_lock_fds(): void
    {
        $wrapper = base_path('bin/schedule-run.sh');

        $this->assertFileExists($wrapper);
        $this->assertTrue(is_executable($wrapper), 'bin/schedule-run.sh must be executable for Hostinger Cron #1');

        $contents = (string) file_get_contents($wrapper);

        $this->assertStringContainsString('cron_lock', $contents);
        $this->assertStringContainsString('exec', $contents);
        $this->assertStringContainsString('artisan schedule:run', $contents);
        $this->assertStringContainsString('php://fd', $contents);
        $this->assertStringNotContainsString('HostCronLockReleaser', $contents);
    }
}
