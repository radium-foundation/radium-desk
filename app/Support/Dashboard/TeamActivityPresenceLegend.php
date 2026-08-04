<?php

namespace App\Support\Dashboard;

use App\Enums\TeamActivityStatus;

/**
 * Presentation-only Presence abbreviation legend for Team Activity.
 * Does not affect status resolution, attendance, or presence calculations.
 */
class TeamActivityPresenceLegend
{
    /**
     * @return list<array{abbr: string, label: string, future?: bool}>
     */
    public static function entries(): array
    {
        return [
            ['abbr' => 'A', 'label' => 'Active'],
            ['abbr' => 'I', 'label' => 'Idle'],
            ['abbr' => 'P', 'label' => 'Pending'],
            ['abbr' => 'B', 'label' => 'Busy'],
            ['abbr' => 'BR', 'label' => 'On Break'],
            ['abbr' => 'ALO', 'label' => 'Auto Logged Out'],
            ['abbr' => 'LV', 'label' => 'On Leave'],
            ['abbr' => 'NLI', 'label' => 'Not Logged In'],
            ['abbr' => 'SNS', 'label' => 'Shift Not Started'],
            ['abbr' => 'SE', 'label' => 'Shift Ended'],
            ['abbr' => 'NS', 'label' => 'No Schedule'],
            ['abbr' => 'OFF', 'label' => 'Offline'],
            ['abbr' => 'L', 'label' => 'Late'],
            ['abbr' => 'OT', 'label' => 'Overtime', 'future' => true],
            ['abbr' => 'WFH', 'label' => 'Work From Home', 'future' => true],
        ];
    }

    /**
     * Compact operational code for a Team Activity status (presentation only).
     */
    public static function codeFor(TeamActivityStatus $status): string
    {
        return match ($status) {
            TeamActivityStatus::Working,
            TeamActivityStatus::Login,
            TeamActivityStatus::Assignment,
            TeamActivityStatus::Remark,
            TeamActivityStatus::StatusChanged,
            TeamActivityStatus::SerialUpdated,
            TeamActivityStatus::ModelUpdated,
            TeamActivityStatus::Refund,
            TeamActivityStatus::Approval => 'A',
            TeamActivityStatus::Idle => 'I',
            TeamActivityStatus::WaitingCustomer => 'P',
            TeamActivityStatus::OnIvr,
            TeamActivityStatus::Email,
            TeamActivityStatus::Whatsapp,
            TeamActivityStatus::Ira => 'B',
            TeamActivityStatus::Break => 'BR',
            TeamActivityStatus::AutoLogout => 'ALO',
            TeamActivityStatus::Leave => 'LV',
            TeamActivityStatus::NotLoggedIn => 'NLI',
            TeamActivityStatus::NotStartedShift => 'SNS',
            TeamActivityStatus::OffDuty => 'SE',
            TeamActivityStatus::NoSchedule => 'NS',
            TeamActivityStatus::Offline,
            TeamActivityStatus::Unknown => 'OFF',
            TeamActivityStatus::Logout => 'LO',
        };
    }
}
