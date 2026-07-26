<?php

namespace App\Support\Dashboard;

use App\Enums\TeamActivityStatus;
use App\Enums\TeamAvailabilityStatus;
use App\Enums\WorkCalendarDayStatus;
use App\Enums\WorkSessionEndReason;
use App\Models\AuditLog;
use Illuminate\Support\Carbon;

class TeamActivityStatusResolver
{
    /**
     * Statuses that may overlay Active from the latest business audit.
     * Assignment / Remark / Status Change / Serial / Model never become current status.
     *
     * @var list<string>
     */
    private const ALLOWED_EVENT_STATUSES = [
        'on_ivr',
        'break',
        'waiting_customer',
        'email',
        'whatsapp',
        'ira',
    ];

    /**
     * @param  array<string, mixed>  $member  Row from TeamAvailabilityOverviewService
     */
    public function resolve(array $member, ?AuditLog $latestAudit = null): TeamActivityStatus
    {
        $authority = $member['authority'] ?? [];
        $blockReasons = $authority['block_reasons'] ?? [];
        $presence = $member['presence'] ?? [];
        $sessionSummary = $member['session_summary'] ?? [];
        $workCalendar = $member['work_calendar'] ?? [];
        $onDuty = (bool) ($member['on_duty'] ?? false);

        if (in_array('approved_leave', $blockReasons, true)) {
            return TeamActivityStatus::Leave;
        }

        if (! $onDuty) {
            if ($this->isAutoLogout($sessionSummary)) {
                return TeamActivityStatus::AutoLogout;
            }

            if ($this->isNotStartedShift($workCalendar)) {
                return TeamActivityStatus::NotStartedShift;
            }

            return TeamActivityStatus::OffDuty;
        }

        $sessionOpen = (bool) ($presence['session_open'] ?? false);

        if (! $sessionOpen) {
            if ($this->isAutoLogout($sessionSummary)) {
                return TeamActivityStatus::AutoLogout;
            }

            if ($this->isNotStartedShift($workCalendar)) {
                return TeamActivityStatus::NotStartedShift;
            }

            return TeamActivityStatus::OffDuty;
        }

        if ($latestAudit !== null) {
            $overlay = $this->overlayFromEvent((string) $latestAudit->event);

            if ($overlay !== null) {
                return $overlay;
            }
        }

        if ($this->isOnBreak($authority, $workCalendar)) {
            return TeamActivityStatus::Break;
        }

        return TeamActivityStatus::Working;
    }

    /**
     * Compact secondary line for operational metadata.
     *
     * @param  array<string, mixed>  $member
     */
    public function workingLabel(array $member, TeamActivityStatus $status): ?string
    {
        return match ($status) {
            TeamActivityStatus::Leave => $this->leaveWorkingLabel($member),
            TeamActivityStatus::AutoLogout => null,
            TeamActivityStatus::OffDuty,
            TeamActivityStatus::Logout => $this->offDutyWorkingLabel($member),
            TeamActivityStatus::NotStartedShift => $this->notStartedWorkingLabel($member),
            default => $this->activeWorkingLabel($member),
        };
    }

    /**
     * @param  array<string, mixed>  $member
     */
    private function leaveWorkingLabel(array $member): ?string
    {
        $reason = $member['leave_reason'] ?? null;

        if (is_string($reason) && $reason !== '') {
            return $reason;
        }

        return 'Leave';
    }

    /**
     * @param  array<string, mixed>  $member
     */
    private function offDutyWorkingLabel(array $member): ?string
    {
        $end = $this->shiftEndLabel($member);

        return $end !== null ? 'Shift ended '.$end : 'Off Duty';
    }

    /**
     * @param  array<string, mixed>  $member
     */
    private function notStartedWorkingLabel(array $member): ?string
    {
        $start = $this->shiftStartLabel($member);

        return $start !== null ? 'Shift starts '.$start : 'Not Started';
    }

    /**
     * @param  array<string, mixed>  $member
     */
    private function activeWorkingLabel(array $member): ?string
    {
        $presence = $member['presence'] ?? [];
        $since = $this->formatSince($presence);
        $duration = $this->formatActiveDuration($presence);
        $overtime = $this->formatOvertimeSuffix($presence);

        if ($since === null && $duration === null) {
            return null;
        }

        if ($since !== null && $duration !== null) {
            return 'Since '.$since.' • '.$duration.$overtime;
        }

        if ($since !== null) {
            return 'Since '.$since.$overtime;
        }

        return $duration.$overtime;
    }

    /**
     * @param  array<string, mixed>  $sessionSummary
     */
    private function isAutoLogout(array $sessionSummary): bool
    {
        return ($sessionSummary['last_ended_reason'] ?? null) === WorkSessionEndReason::AwayTimeout->value;
    }

    /**
     * @param  array<string, mixed>  $workCalendar
     */
    private function isNotStartedShift(array $workCalendar): bool
    {
        return ($workCalendar['status'] ?? '') === WorkCalendarDayStatus::StartsLater->value;
    }

    /**
     * @param  array<string, mixed>  $authority
     * @param  array<string, mixed>  $workCalendar
     */
    private function isOnBreak(array $authority, array $workCalendar): bool
    {
        if (($workCalendar['status'] ?? '') === WorkCalendarDayStatus::Lunch->value) {
            return true;
        }

        $storedAvailability = $authority['stored_availability'] ?? null;

        return $storedAvailability === TeamAvailabilityStatus::Busy->value;
    }

    /**
     * @param  array<string, mixed>  $member
     */
    private function shiftStartLabel(array $member): ?string
    {
        $shiftTimes = $member['shift_times'] ?? [];

        return is_array($shiftTimes) ? ($shiftTimes['start'] ?? null) : null;
    }

    /**
     * @param  array<string, mixed>  $member
     */
    private function shiftEndLabel(array $member): ?string
    {
        $shiftTimes = $member['shift_times'] ?? [];

        return is_array($shiftTimes) ? ($shiftTimes['end'] ?? null) : null;
    }

    private function overlayFromEvent(string $event): ?TeamActivityStatus
    {
        $map = config('dashboard-team-activity.event_status_map', []);
        $key = $map[$event] ?? null;

        if (! is_string($key) || $key === '' || ! in_array($key, self::ALLOWED_EVENT_STATUSES, true)) {
            return null;
        }

        return TeamActivityStatus::tryFromConfig($key);
    }

    /**
     * @param  array<string, mixed>  $presence
     */
    private function formatSince(array $presence): ?string
    {
        $iso = $presence['login_at_iso'] ?? null;

        if (is_string($iso) && $iso !== '') {
            try {
                return Carbon::parse($iso)->format('g:i A');
            } catch (\Throwable) {
                // Fall through to H:i login_at.
            }
        }

        $loginAt = $presence['login_at'] ?? null;

        if (! is_string($loginAt) || $loginAt === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('H:i', $loginAt)->format('g:i A');
        } catch (\Throwable) {
            return $loginAt;
        }
    }

    /**
     * @param  array<string, mixed>  $presence
     */
    private function formatActiveDuration(array $presence): ?string
    {
        $duration = $presence['active_duration'] ?? null;

        if (! is_string($duration) || $duration === '' || $duration === '0m') {
            return null;
        }

        return $duration;
    }

    /**
     * @param  array<string, mixed>  $presence
     */
    private function formatOvertimeSuffix(array $presence): string
    {
        $overtime = $presence['overtime_duration'] ?? null;

        if (! is_string($overtime) || $overtime === '' || $overtime === '0m') {
            return '';
        }

        return ' (+'.$overtime.' OT)';
    }
}
