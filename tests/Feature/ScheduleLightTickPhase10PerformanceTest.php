<?php

namespace Tests\Feature;

use App\Console\Commands\ScheduleLightTickCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Phase 10 — schedule:light-tick consolidates four every-minute light jobs
 * into one artisan process (same underlying commands).
 */
class ScheduleLightTickPhase10PerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_light_tick_invokes_underlying_commands_and_reports_summary(): void
    {
        $this->artisan('schedule:light-tick')
            ->expectsOutputToContain('Processed')
            ->expectsOutputToContain('Flushed')
            ->expectsOutputToContain('Light tick finished: ran 4, skipped 0, failed 0.')
            ->assertSuccessful();
    }

    public function test_light_tick_skips_disabled_steps_without_failing(): void
    {
        config([
            'service_case_assignment.automation_grace_period_enabled' => false,
            'ira.communication.assignment_telegram_batch.enabled' => false,
        ]);

        $this->artisan('schedule:light-tick')
            ->expectsOutputToContain('Skipped service-cases:process-automation-pending (disabled).')
            ->expectsOutputToContain('Skipped ira:flush-assignment-telegram-batches (disabled).')
            ->expectsOutputToContain('Light tick finished: ran 2, skipped 2, failed 0.')
            ->assertSuccessful();
    }

    public function test_light_tick_wires_configured_outbox_limit(): void
    {
        config(['scheduler.outbox_process_limit' => 3]);

        $command = $this->app->make(ScheduleLightTickCommand::class);
        $method = new ReflectionMethod(ScheduleLightTickCommand::class, 'buildSteps');
        $steps = $method->invoke($command);

        $outbox = collect($steps)->firstWhere('command', 'outbox:process');

        $this->assertNotNull($outbox);
        $this->assertSame(3, (int) $outbox['parameters']['--limit']);
        $this->assertTrue($outbox['when']);
    }

    public function test_light_tick_wires_configured_automation_pending_limit(): void
    {
        config(['scheduler.automation_pending_limit' => 7]);

        $command = $this->app->make(ScheduleLightTickCommand::class);
        $method = new ReflectionMethod(ScheduleLightTickCommand::class, 'buildSteps');
        $steps = $method->invoke($command);

        $pending = collect($steps)->firstWhere('command', 'service-cases:process-automation-pending');

        $this->assertNotNull($pending);
        $this->assertSame(7, (int) $pending['parameters']['--limit']);
        $this->assertTrue($pending['when']);
    }
}
