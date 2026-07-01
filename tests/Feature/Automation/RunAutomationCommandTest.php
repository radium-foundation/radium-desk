<?php

namespace Tests\Feature\Automation;

use App\Data\Automation\AutomationSchedulerRunResult;
use App\Models\SystemSetting;
use App\Services\Automation\AutomationSchedulerService;
use App\Services\SystemSettingsService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RunAutomationCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_command_exits_gracefully_when_scheduler_is_disabled(): void
    {
        $this->setSchedulerEnabled(false);

        $this->mock(AutomationSchedulerService::class)
            ->shouldReceive('run')
            ->once()
            ->andReturn(AutomationSchedulerRunResult::disabled());

        $this->artisan('automation:run')
            ->expectsOutput('Automation scheduler is disabled.')
            ->assertSuccessful();
    }

    public function test_command_delegates_to_scheduler_service_with_chunk_size(): void
    {
        $this->setSchedulerEnabled(true);

        $this->mock(AutomationSchedulerService::class)
            ->shouldReceive('run')
            ->once()
            ->with(null, 25)
            ->andReturn(new AutomationSchedulerRunResult(
                enabled: true,
                waitingStatesScanned: 2,
                dueActionsFound: 3,
                executed: 2,
                skipped: 1,
                failures: 0,
            ));

        $this->artisan('automation:run', ['--chunk' => '25'])
            ->expectsOutput('Scanned 2 waiting state(s); found 3 due action(s); executed 2; skipped 1; failures 0.')
            ->assertSuccessful();
    }

    private function setSchedulerEnabled(bool $enabled): void
    {
        SystemSetting::query()->updateOrCreate(
            ['key' => 'automation.scheduler.enabled'],
            ['value' => $enabled ? '1' : '0'],
        );

        app(SystemSettingsService::class)->forget('automation.scheduler.enabled');
    }
}
