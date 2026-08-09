<?php

namespace Tests\Feature;

use App\Listeners\LogScheduledTaskTiming;
use App\Support\SchedulerTimingLogger;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use Throwable;

class SchedulerTimingTelemetryTest extends TestCase
{
    use RefreshDatabase;

    private string $timingDir;

    private string $sampleScript;

    protected function setUp(): void
    {
        parent::setUp();

        $this->timingDir = storage_path('logs/scheduler-timing');
        $this->sampleScript = base_path('bin/cpu-process-sample.sh');

        File::deleteDirectory($this->timingDir);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->timingDir)) {
            File::deleteDirectory($this->timingDir);
        } elseif (is_file($this->timingDir)) {
            @unlink($this->timingDir);
        }

        parent::tearDown();
    }

    public function test_scheduler_start_and_finished_records_are_written(): void
    {
        $this->artisan('schedule:list')->assertSuccessful();

        $task = collect(app(Schedule::class)->events())->first(
            fn ($event): bool => is_string($event->command)
                && str_contains($event->command, 'schedule:light-tick'),
        );

        $this->assertNotNull($task);

        Event::dispatch(new ScheduledTaskStarting($task));
        Event::dispatch(new ScheduledTaskFinished($task, 1.25));

        $records = $this->readTimingRecords();
        $this->assertNotEmpty($records);

        $starting = collect($records)->firstWhere('event', 'starting');
        $finished = collect($records)->firstWhere('event', 'finished');

        $this->assertNotNull($starting);
        $this->assertSame('schedule:light-tick', $starting['command']);
        $this->assertArrayHasKey('ts', $starting);
        $this->assertArrayHasKey('ts_utc', $starting);
        $this->assertFalse((bool) $starting['run_in_background']);

        $this->assertNotNull($finished);
        $this->assertSame('schedule:light-tick', $finished['command']);
        $this->assertSame(1250, $finished['duration_ms']);
    }

    public function test_light_tick_emits_step_timing_lines(): void
    {
        config([
            'service_case_assignment.automation_grace_period_enabled' => false,
            'ira.communication.assignment_telegram_batch.enabled' => false,
        ]);

        $this->artisan('schedule:light-tick')->assertSuccessful();

        $steps = collect($this->readTimingRecords())
            ->where('event', 'light_tick_step')
            ->values();

        $this->assertCount(2, $steps);
        $this->assertSame('outbox:process', $steps[0]['command']);
        $this->assertSame('presence:process-timeouts', $steps[1]['command']);
        $this->assertSame('schedule:light-tick', $steps[0]['parent']);
        $this->assertArrayHasKey('duration_ms', $steps[0]);
        $this->assertArrayHasKey('exit', $steps[0]);
    }

    public function test_bg_finished_event_is_recorded(): void
    {
        $this->artisan('schedule:list')->assertSuccessful();

        $task = collect(app(Schedule::class)->events())->first(
            fn ($event): bool => is_string($event->command)
                && str_contains($event->command, 'automation:snapshot')
                && ! str_contains($event->command, '--reconcile'),
        );

        $this->assertNotNull($task);
        $this->assertTrue((bool) $task->runInBackground);

        Event::dispatch(new ScheduledBackgroundTaskFinished($task));

        $bg = collect($this->readTimingRecords())->firstWhere('event', 'bg_finished');

        $this->assertNotNull($bg);
        $this->assertTrue(str_contains((string) $bg['command'], 'automation:snapshot'));
        $this->assertTrue((bool) $bg['run_in_background']);
    }

    public function test_telemetry_failure_does_not_break_command_path(): void
    {
        File::ensureDirectoryExists(dirname($this->timingDir));
        File::deleteDirectory($this->timingDir);
        file_put_contents($this->timingDir, 'not-a-directory');

        $this->artisan('schedule:light-tick')
            ->expectsOutputToContain('Light tick finished:')
            ->assertSuccessful();

        $this->assertFileExists($this->timingDir);
        $this->assertTrue(is_file($this->timingDir));
    }

    public function test_listener_swallows_logger_exceptions(): void
    {
        $this->artisan('schedule:list')->assertSuccessful();

        $task = collect(app(Schedule::class)->events())->first(
            fn ($event): bool => is_string($event->command)
                && str_contains($event->command, 'schedule:light-tick'),
        );

        $this->assertNotNull($task);

        $this->app->instance(SchedulerTimingLogger::class, new class extends SchedulerTimingLogger
        {
            public function write(array $payload): void
            {
                throw new \RuntimeException('forced telemetry failure');
            }
        });

        $listener = $this->app->make(LogScheduledTaskTiming::class);

        try {
            $listener->handle(new ScheduledTaskStarting($task));
        } catch (Throwable $e) {
            $this->fail('Listener must not rethrow telemetry failures: '.$e->getMessage());
        }

        $this->assertTrue(true);
    }

    public function test_cpu_sampler_script_syntax_and_lock_order(): void
    {
        $this->assertFileExists($this->sampleScript);
        $this->assertTrue(is_executable($this->sampleScript));

        $syntax = [];
        $code = 0;
        exec('bash -n '.escapeshellarg($this->sampleScript).' 2>&1', $syntax, $code);
        $this->assertSame(0, $code, implode("\n", $syntax));

        $contents = (string) file_get_contents($this->sampleScript);

        // Ignore comments when asserting lock FD order.
        $codeOnly = preg_replace('/^\s*#.*$/m', '', $contents) ?? $contents;

        $this->assertMatchesRegularExpression(
            '/exec\s+9>"\$LOCK"[\s\S]*?flock\s+-n\s+9/',
            $codeOnly,
            'FD 9 must be opened before flock -n 9',
        );
        $this->assertDoesNotMatchRegularExpression(
            '/flock\s+-n\s+9[\s\S]*?exec\s+9>"\$LOCK"/',
            $codeOnly,
            'Must not flock before opening FD 9',
        );
        $this->assertStringNotContainsString('php artisan', $contents);
        $this->assertStringContainsString('storage/logs/cpu-process-samples', $contents);
        $this->assertStringNotContainsString("OUT_DIR=\"/tmp", $contents);
        $this->assertStringNotContainsString("LOCK=/tmp", $contents);
    }

    public function test_cpu_sampler_skips_quietly_when_lock_held(): void
    {
        if (! $this->flockAvailable()) {
            $this->markTestSkipped('flock(1) is required for overlap-skip coverage (available on Hostinger).');
        }

        $outDir = storage_path('logs/cpu-process-samples');
        File::ensureDirectoryExists($outDir);
        $lock = $outDir.'/sample.lock';

        $holder = proc_open(
            'bash -c '.escapeshellarg('exec 9>"'.str_replace("'", "'\\''", $lock).'"; flock -n 9 || exit 1; sleep 8'),
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );

        $this->assertIsResource($holder);
        usleep(200_000);

        $cmd = sprintf(
            'CPU_SAMPLE_COUNT=1 CPU_SAMPLE_INTERVAL=0 %s',
            escapeshellarg($this->sampleScript),
        );
        $output = [];
        $exit = 0;
        exec($cmd.' 2>&1', $output, $exit);

        $this->assertSame(0, $exit, implode("\n", $output));

        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_terminate($holder);
        proc_close($holder);
    }

    private function flockAvailable(): bool
    {
        $output = [];
        $code = 1;
        exec('command -v flock 2>/dev/null', $output, $code);

        return $code === 0;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readTimingRecords(): array
    {
        if (! is_dir($this->timingDir)) {
            return [];
        }

        $files = glob($this->timingDir.'/*.jsonl') ?: [];
        $records = [];

        foreach ($files as $file) {
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            foreach ($lines as $line) {
                $decoded = json_decode($line, true);
                if (is_array($decoded)) {
                    $records[] = $decoded;
                }
            }
        }

        return $records;
    }
}
