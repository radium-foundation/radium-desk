<?php

namespace Tests\Unit\Operations;

use App\Services\Operations\AdminOperationalQuietHoursService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AdminOperationalQuietHoursServiceTest extends TestCase
{
    private AdminOperationalQuietHoursService $quietHours;

    protected function setUp(): void
    {
        parent::setUp();

        $this->quietHours = app(AdminOperationalQuietHoursService::class);

        config([
            'ira.communication.admin_quiet_hours.enabled' => true,
            'ira.communication.admin_quiet_hours.start' => '18:30',
            'ira.communication.admin_quiet_hours.end' => '09:00',
            'app.schedule_timezone' => 'Asia/Kolkata',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_admin_quiet_hours_start_at_1830(): void
    {
        $this->assertTrue($this->quietHours->isQuietHours(
            Carbon::parse('2026-07-09 18:30:00', 'Asia/Kolkata'),
        ));
    }

    public function test_admin_quiet_hours_before_start_is_not_quiet(): void
    {
        $this->assertFalse($this->quietHours->isQuietHours(
            Carbon::parse('2026-07-09 18:29:00', 'Asia/Kolkata'),
        ));
    }

    public function test_admin_quiet_hours_end_at_0900_is_not_quiet(): void
    {
        $this->assertFalse($this->quietHours->isQuietHours(
            Carbon::parse('2026-07-10 09:00:00', 'Asia/Kolkata'),
        ));
    }

    public function test_admin_quiet_hours_uses_schedule_timezone(): void
    {
        config(['app.schedule_timezone' => 'America/New_York']);

        $at = Carbon::parse('2026-07-09 22:00:00', 'Asia/Kolkata');

        $this->assertFalse($this->quietHours->isQuietHours($at));
    }
}
