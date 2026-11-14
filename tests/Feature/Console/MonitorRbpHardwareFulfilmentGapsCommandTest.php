<?php

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonitorRbpHardwareFulfilmentGapsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_runs_when_monitor_disabled(): void
    {
        config(['hardware_fulfilment.rbp_gap_monitor.enabled' => false]);

        $this->artisan('desk:monitor-rbp-hardware-fulfilment-gaps')
            ->expectsOutputToContain('disabled')
            ->assertSuccessful();
    }

    public function test_command_emits_json_summary(): void
    {
        $this->artisan('desk:monitor-rbp-hardware-fulfilment-gaps', ['--json' => true])
            ->assertSuccessful();
    }
}