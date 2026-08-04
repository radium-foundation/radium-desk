<?php

namespace Tests\Unit\Dashboard;

use App\Enums\PresenceStatus;
use App\Enums\TeamActivityStatus;
use App\Enums\TeamAvailabilityStatus;
use App\Enums\WorkCalendarDayStatus;
use App\Enums\WorkSessionEndReason;
use App\Models\AuditLog;
use App\Support\Dashboard\TeamActivityStatusResolver;
use Tests\TestCase;

class TeamActivityStatusResolverTest extends TestCase
{
    public function test_resolves_leave_from_block_reasons(): void
    {
        $resolver = new TeamActivityStatusResolver;

        $status = $resolver->resolve([
            'on_duty' => false,
            'authority' => ['block_reasons' => ['approved_leave']],
            'presence' => [],
            'session_summary' => [],
            'work_calendar' => [],
        ]);

        $this->assertSame(TeamActivityStatus::Leave, $status);
    }

    public function test_leave_working_label_uses_reason_when_present(): void
    {
        $resolver = new TeamActivityStatusResolver;

        $label = $resolver->workingLabel([
            'leave_reason' => 'Annual Leave',
            'presence' => [],
            'session_summary' => [],
        ], TeamActivityStatus::Leave);

        $this->assertSame('Annual Leave', $label);
    }

    public function test_resolves_auto_logout_from_session_summary(): void
    {
        $resolver = new TeamActivityStatusResolver;

        $status = $resolver->resolve([
            'on_duty' => false,
            'authority' => ['block_reasons' => []],
            'presence' => ['session_open' => false],
            'session_summary' => [
                'last_ended_reason' => WorkSessionEndReason::AwayTimeout->value,
            ],
            'work_calendar' => [],
        ]);

        $this->assertSame(TeamActivityStatus::AutoLogout, $status);
    }

    public function test_resolves_not_started_shift_before_shift_window(): void
    {
        $resolver = new TeamActivityStatusResolver;

        $status = $resolver->resolve([
            'on_duty' => false,
            'authority' => ['block_reasons' => ['not_present']],
            'presence' => ['session_open' => false],
            'session_summary' => [],
            'work_calendar' => ['status' => WorkCalendarDayStatus::StartsLater->value],
            'shift_times' => ['start' => '2:00 PM', 'end' => '6:00 PM'],
        ]);

        $this->assertSame(TeamActivityStatus::NotStartedShift, $status);
        $this->assertSame(
            'Shift starts 2:00 PM',
            $resolver->workingLabel([
                'shift_times' => ['start' => '2:00 PM', 'end' => '6:00 PM'],
            ], $status),
        );
    }

    public function test_resolves_off_duty_for_logged_out_members(): void
    {
        $resolver = new TeamActivityStatusResolver;

        $status = $resolver->resolve([
            'on_duty' => false,
            'authority' => ['block_reasons' => ['not_present']],
            'presence' => ['session_open' => false],
            'session_summary' => [],
            'work_calendar' => ['status' => WorkCalendarDayStatus::OutsideHours->value],
            'shift_times' => ['start' => '9:00 AM', 'end' => '6:00 PM'],
        ]);

        $this->assertSame(TeamActivityStatus::NotLoggedIn, $status);
        $this->assertSame('Not Logged In', $status->label());
        $this->assertNull($resolver->workingLabel([
            'shift_times' => ['start' => '9:00 AM', 'end' => '6:00 PM'],
        ], $status));
    }

    public function test_resolves_not_logged_in_during_shift_without_session(): void
    {
        $resolver = new TeamActivityStatusResolver;

        $status = $resolver->resolve([
            'on_duty' => false,
            'authority' => ['block_reasons' => ['not_present']],
            'presence' => ['session_open' => false],
            'session_summary' => [],
            'work_calendar' => ['status' => WorkCalendarDayStatus::Working->value],
            'shift_times' => ['start' => '9:00 AM', 'end' => '6:00 PM'],
        ]);

        $this->assertSame(TeamActivityStatus::NotLoggedIn, $status);
        $this->assertSame('Not Logged In', $status->label());
    }

    public function test_resolves_no_schedule_when_calendar_has_no_schedule(): void
    {
        $resolver = new TeamActivityStatusResolver;

        $status = $resolver->resolve([
            'on_duty' => false,
            'authority' => ['block_reasons' => ['not_present']],
            'presence' => ['session_open' => false],
            'session_summary' => [],
            'work_calendar' => ['status' => WorkCalendarDayStatus::NoSchedule->value],
        ]);

        $this->assertSame(TeamActivityStatus::NoSchedule, $status);
        $this->assertSame('No Schedule', $status->label());
    }

    public function test_open_session_overrides_auto_logout_even_when_off_duty(): void
    {
        $resolver = new TeamActivityStatusResolver;

        $status = $resolver->resolve([
            'on_duty' => false,
            'authority' => [
                'block_reasons' => ['calendar_blocked', 'availability_offline'],
                'stored_availability' => TeamAvailabilityStatus::Offline->value,
            ],
            'presence' => [
                'session_open' => true,
                'status' => PresenceStatus::Active->value,
            ],
            'session_summary' => [
                'last_ended_reason' => WorkSessionEndReason::AwayTimeout->value,
            ],
            'work_calendar' => ['status' => WorkCalendarDayStatus::WeeklyOff->value],
        ]);

        $this->assertSame(TeamActivityStatus::Working, $status);
    }

    public function test_calendar_badge_shows_weekly_off_without_replacing_status(): void
    {
        $resolver = new TeamActivityStatusResolver;

        $badge = $resolver->calendarBadge([
            'work_calendar' => ['status' => WorkCalendarDayStatus::WeeklyOff->value],
        ]);

        $this->assertSame('Weekly Off', $badge);
    }

    public function test_offline_when_no_sessions_today_and_no_open_session(): void
    {
        $resolver = new TeamActivityStatusResolver;

        $status = $resolver->resolve([
            'on_duty' => false,
            'authority' => ['block_reasons' => []],
            'presence' => ['session_open' => false],
            'session_summary' => [],
            'work_calendar' => ['status' => WorkCalendarDayStatus::OutsideHours->value],
        ]);

        $this->assertSame(TeamActivityStatus::NotLoggedIn, $status);
    }

    public function test_offline_reserved_for_weekly_off_without_session(): void
    {
        $resolver = new TeamActivityStatusResolver;

        $status = $resolver->resolve([
            'on_duty' => false,
            'authority' => ['block_reasons' => []],
            'presence' => ['session_open' => false],
            'session_summary' => [],
            'work_calendar' => ['status' => WorkCalendarDayStatus::WeeklyOff->value],
        ]);

        $this->assertSame(TeamActivityStatus::Offline, $status);
        $this->assertSame('Offline', $status->label());
    }

    public function test_idle_presence_resolves_to_idle_workforce_status(): void
    {
        $resolver = new TeamActivityStatusResolver;

        $status = $resolver->resolve([
            'on_duty' => true,
            'authority' => ['block_reasons' => [], 'stored_availability' => TeamAvailabilityStatus::Available->value],
            'presence' => [
                'session_open' => true,
                'status' => PresenceStatus::Idle->value,
            ],
            'session_summary' => [],
            'work_calendar' => ['status' => WorkCalendarDayStatus::Working->value],
        ]);

        $this->assertSame(TeamActivityStatus::Idle, $status);
        $this->assertSame('Idle', $status->label());
    }

    public function test_busy_availability_resolves_to_break_when_on_duty(): void
    {
        $resolver = new TeamActivityStatusResolver;

        $status = $resolver->resolve([
            'on_duty' => true,
            'authority' => [
                'block_reasons' => [],
                'stored_availability' => TeamAvailabilityStatus::Busy->value,
            ],
            'presence' => [
                'session_open' => true,
                'status' => PresenceStatus::Active->value,
            ],
            'session_summary' => [],
            'work_calendar' => ['status' => WorkCalendarDayStatus::Working->value],
        ]);

        $this->assertSame(TeamActivityStatus::Break, $status);
        $this->assertSame('On Break', $status->label());
    }

    public function test_assignment_audit_never_becomes_current_status(): void
    {
        $resolver = new TeamActivityStatusResolver;
        $audit = new AuditLog(['event' => 'service_case.assigned']);

        $status = $resolver->resolve([
            'on_duty' => true,
            'authority' => [
                'block_reasons' => [],
                'stored_availability' => TeamAvailabilityStatus::Available->value,
            ],
            'presence' => [
                'session_open' => true,
                'status' => PresenceStatus::Active->value,
            ],
            'session_summary' => [],
            'work_calendar' => ['status' => WorkCalendarDayStatus::Working->value],
        ], $audit);

        $this->assertSame(TeamActivityStatus::Working, $status);
    }

    public function test_waiting_customer_audit_can_overlay_status(): void
    {
        $resolver = new TeamActivityStatusResolver;
        $audit = new AuditLog(['event' => 'service_case.customer_waiting_started']);

        $status = $resolver->resolve([
            'on_duty' => true,
            'authority' => [
                'block_reasons' => [],
                'stored_availability' => TeamAvailabilityStatus::Available->value,
            ],
            'presence' => [
                'session_open' => true,
                'status' => PresenceStatus::Active->value,
            ],
            'session_summary' => [],
            'work_calendar' => ['status' => WorkCalendarDayStatus::Working->value],
        ], $audit);

        $this->assertSame(TeamActivityStatus::WaitingCustomer, $status);
        $this->assertSame('Pending', $status->label());
    }

    public function test_ira_automation_audit_can_overlay_status(): void
    {
        $resolver = new TeamActivityStatusResolver;
        $audit = new AuditLog(['event' => 'service_case.automation.validation_passed']);

        $status = $resolver->resolve([
            'on_duty' => true,
            'authority' => [
                'block_reasons' => [],
                'stored_availability' => TeamAvailabilityStatus::Available->value,
            ],
            'presence' => [
                'session_open' => true,
                'status' => PresenceStatus::Active->value,
            ],
            'session_summary' => [],
            'work_calendar' => ['status' => WorkCalendarDayStatus::Working->value],
        ], $audit);

        $this->assertSame(TeamActivityStatus::Ira, $status);
    }

    public function test_working_label_is_null_when_presence_metrics_are_shown_separately(): void
    {
        $resolver = new TeamActivityStatusResolver;

        $label = $resolver->workingLabel([
            'presence' => [
                'active_duration' => '5h 12m',
                'login_at' => '09:00',
                'overtime_duration' => '1h 8m',
            ],
            'session_summary' => [],
        ], TeamActivityStatus::Working);

        $this->assertNull($label);
    }
}
