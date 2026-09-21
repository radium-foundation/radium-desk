<?php

namespace Tests\Feature\Retention;

use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class RetentionEmailScheduleCommandTest extends TestCase
{
    public function test_schedule_wrapper_script_exists_and_is_executable(): void
    {
        $path = base_path('bin/email-retention-schedule.sh');

        $this->assertFileExists($path);
        $this->assertNotFalse(@is_executable($path));
    }

    public function test_scheduler_defaults_to_disabled(): void
    {
        $this->assertFalse((bool) config('retention.email_retention.scheduler_enabled', false));
    }

    public function test_bootstrap_registers_retention_schedule_entries(): void
    {
        $contents = file_get_contents(base_path('bootstrap/app.php'));

        $this->assertIsString($contents);
        $this->assertStringContainsString('email-retention-schedule.sh --dry-run-only', $contents);
        $this->assertStringContainsString('email-retention-schedule.sh --weekly-execute', $contents);
        $this->assertStringContainsString('retention.email_retention.scheduler_enabled', $contents);
    }

    public function test_command_requires_exactly_one_mode_flag(): void
    {
        Config::set('retention.email_retention.scheduler_enabled', true);

        $this->artisan('database:retention-email-schedule', [
            '--dry-run-only' => true,
            '--weekly-execute' => true,
        ])->assertFailed();
    }
}
