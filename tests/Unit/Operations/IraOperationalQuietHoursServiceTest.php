<?php

namespace Tests\Unit\Operations;

use App\Services\Operations\IraOperationalQuietHoursService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class IraOperationalQuietHoursServiceTest extends TestCase
{
    private IraOperationalQuietHoursService $quietHours;

    protected function setUp(): void
    {
        parent::setUp();

        $this->quietHours = app(IraOperationalQuietHoursService::class);

        config([
            'ira.communication.quiet_hours.enabled' => true,
            'ira.communication.quiet_hours.start' => '21:00',
            'ira.communication.quiet_hours.end' => '08:00',
            'app.schedule_timezone' => 'Asia/Kolkata',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_quiet_hours_boundary_before_start_is_not_quiet(): void
    {
        $at = Carbon::parse('2026-07-09 20:59:00', 'Asia/Kolkata');

        $this->assertFalse($this->quietHours->isQuietHours($at));
    }

    public function test_quiet_hours_boundary_at_start_is_quiet(): void
    {
        $at = Carbon::parse('2026-07-09 21:00:00', 'Asia/Kolkata');

        $this->assertTrue($this->quietHours->isQuietHours($at));
    }

    public function test_quiet_hours_boundary_before_end_is_still_quiet(): void
    {
        $at = Carbon::parse('2026-07-10 07:59:00', 'Asia/Kolkata');

        $this->assertTrue($this->quietHours->isQuietHours($at));
    }

    public function test_quiet_hours_boundary_at_end_is_not_quiet(): void
    {
        $at = Carbon::parse('2026-07-10 08:00:00', 'Asia/Kolkata');

        $this->assertFalse($this->quietHours->isQuietHours($at));
    }

    public function test_quiet_hours_uses_schedule_timezone_not_carbon_timezone(): void
    {
        config(['app.schedule_timezone' => 'America/New_York']);

        $at = Carbon::parse('2026-07-09 22:00:00', 'Asia/Kolkata');

        $this->assertFalse($this->quietHours->isQuietHours($at));
    }

    public function test_quiet_hours_can_be_disabled_via_config(): void
    {
        config(['ira.communication.quiet_hours.enabled' => false]);

        $at = Carbon::parse('2026-07-09 23:00:00', 'Asia/Kolkata');

        $this->assertFalse($this->quietHours->isQuietHours($at));
    }
}
