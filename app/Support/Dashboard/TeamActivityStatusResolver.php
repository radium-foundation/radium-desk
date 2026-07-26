<?php

namespace App\Support\Dashboard;

use App\Enums\TeamActivityStatus;
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
        $onDuty = (bool) ($member['on_duty'] ?? false);

        if (in_array('approved_leave', $blockReasons, true)) {
            return TeamActivityStatus::Leave;
        }

        if (! $onDuty) {
            return $this->resolveUnavailableStatus($sessionSummary);
        }

        $sessionOpen = (bool) ($presence['session_open'] ?? false);

        if (! $sessionOpen) {
            return $this->resolveUnavailableStatus($sessionSummary);
        }

        if ($latestAudit !== null) {
            $overlay = $this->overlayFromEvent((string) $latestAudit->event);

            if ($overlay !== null) {
                return $overlay;
            }
        }

        // Idle / Away / Active session → Active (single working status).
        return TeamActivityStatus::Working;
    }

    /**
     * Compact secondary line: Since 09:00 AM • 5h 12m (+1h OT)
     *
     * @param  array<string, mixed>  $member
     */
    public function workingLabel(array $member, TeamActivityStatus $status): ?string
    {
        $presence = $member['presence'] ?? [];

        if (in_array($status, [
            TeamActivityStatus::Leave,
            TeamActivityStatus::OffDuty,
            TeamActivityStatus::AutoLogout,
            TeamActivityStatus::Logout,
        ], true)) {
            return null;
        }

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
    private function resolveUnavailableStatus(array $sessionSummary): TeamActivityStatus
    {
        $endedReason = $sessionSummary['last_ended_reason'] ?? null;

        if ($endedReason === WorkSessionEndReason::AwayTimeout->value) {
            return TeamActivityStatus::AutoLogout;
        }

        return TeamActivityStatus::OffDuty;
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
