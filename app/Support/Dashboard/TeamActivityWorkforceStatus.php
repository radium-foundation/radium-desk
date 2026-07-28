<?php

namespace App\Support\Dashboard;

use App\Enums\TeamActivityStatus;

/**
 * Maps internal Team Activity resolver states to workforce availability labels.
 *
 * Presentation only — TeamActivityStatusResolver and sorting logic stay unchanged
 * except where an existing enum case (idle) was already defined but unused.
 *
 * Internal state → workforce label:
 * - working, login, assignment, remark, status_changed, serial_updated,
 *   model_updated, refund, approval → Active (available; no channel overlay)
 * - idle → Idle (session open, presence idle, no overlay)
 * - on_ivr, email, whatsapp, ira → Busy (engaged on a live channel or automation)
 * - waiting_customer → Pending (blocked on external customer input)
 * - break → On Break
 * - leave → On Leave (approved leave; reason shown in secondary line)
 * - offline, unknown → Offline
 * - auto_logout → Auto Logged Out
 * - logout → Logged Out
 * - off_duty → Shift Ended
 * - not_started_shift → Shift Not Started
 */
class TeamActivityWorkforceStatus
{
    public static function labelFor(TeamActivityStatus $status): string
    {
        $configured = config('dashboard-team-activity.statuses.'.$status->value.'.label');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return match ($status) {
            TeamActivityStatus::Working,
            TeamActivityStatus::Login,
            TeamActivityStatus::Assignment,
            TeamActivityStatus::Remark,
            TeamActivityStatus::StatusChanged,
            TeamActivityStatus::SerialUpdated,
            TeamActivityStatus::ModelUpdated,
            TeamActivityStatus::Refund,
            TeamActivityStatus::Approval => 'Active',
            TeamActivityStatus::Idle => 'Idle',
            TeamActivityStatus::OnIvr,
            TeamActivityStatus::Email,
            TeamActivityStatus::Whatsapp,
            TeamActivityStatus::Ira => 'Busy',
            TeamActivityStatus::WaitingCustomer => 'Pending',
            TeamActivityStatus::Break => 'On Break',
            TeamActivityStatus::Leave => 'On Leave',
            TeamActivityStatus::Offline,
            TeamActivityStatus::Unknown => 'Offline',
            TeamActivityStatus::AutoLogout => 'Auto Logged Out',
            TeamActivityStatus::Logout => 'Logged Out',
            TeamActivityStatus::OffDuty => 'Shift Ended',
            TeamActivityStatus::NotStartedShift => 'Shift Not Started',
        };
    }

    /**
     * IRA virtual member uses automation-health labels before workforce mapping.
     */
    public static function labelForIraAutomationState(string $internalLabel): string
    {
        return match ($internalLabel) {
            'Processing' => 'Busy',
            'Waiting RadiumBox', 'Waiting Manual Correction' => 'Pending',
            'Idle' => 'Idle',
            default => 'Busy',
        };
    }
}
