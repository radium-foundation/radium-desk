<?php

namespace App\Support\Dashboard;

use App\Data\TeamActivityAgentRow;
use App\Enums\TeamActivityStatus;

class TeamActivityMemberStatusPresenter
{
    public function __construct(
        private readonly TeamActivityDurationPresenter $durationPresenter,
    ) {}

    public function contextLabel(TeamActivityAgentRow $agent, ?string $latestElapsed): ?string
    {
        if ($agent->isVirtual) {
            return $this->normalizeDuration($latestElapsed);
        }

        return match ($agent->status) {
            TeamActivityStatus::Leave => filled($agent->workingLabel) ? $agent->workingLabel : null,
            TeamActivityStatus::Offline,
            TeamActivityStatus::NotLoggedIn,
            TeamActivityStatus::NoSchedule,
            TeamActivityStatus::Unknown => null,
            TeamActivityStatus::WaitingCustomer,
            TeamActivityStatus::OnIvr,
            TeamActivityStatus::Email,
            TeamActivityStatus::Whatsapp,
            TeamActivityStatus::Ira => $this->normalizeDuration($latestElapsed),
            TeamActivityStatus::Working,
            TeamActivityStatus::Idle,
            TeamActivityStatus::Break => $this->normalizeDuration($agent->currentDurationLabel)
                ?? $this->normalizeDuration($latestElapsed),
            default => filled($agent->workingLabel)
                ? $agent->workingLabel
                : $this->normalizeDuration($latestElapsed),
        };
    }

    /**
     * Secondary line beneath live status in the Presence column.
     * Omits session durations already shown in the Current metric column.
     */
    public function secondaryContextLabel(TeamActivityAgentRow $agent, ?string $latestElapsed): ?string
    {
        if ($agent->isVirtual) {
            return $this->normalizeDuration($latestElapsed);
        }

        return match ($agent->status) {
            TeamActivityStatus::Leave,
            TeamActivityStatus::AutoLogout,
            TeamActivityStatus::OffDuty,
            TeamActivityStatus::NotStartedShift,
            TeamActivityStatus::NotLoggedIn,
            TeamActivityStatus::NoSchedule,
            TeamActivityStatus::Offline => filled($agent->workingLabel) ? $agent->workingLabel : null,
            TeamActivityStatus::WaitingCustomer,
            TeamActivityStatus::OnIvr,
            TeamActivityStatus::Email,
            TeamActivityStatus::Whatsapp,
            TeamActivityStatus::Ira => $this->normalizeDuration($latestElapsed),
            TeamActivityStatus::Working,
            TeamActivityStatus::Idle,
            TeamActivityStatus::Break => null,
            default => filled($agent->workingLabel)
                ? $agent->workingLabel
                : $this->normalizeDuration($latestElapsed),
        };
    }

    /**
     * Compact late minutes label (e.g. "33m") when the attendance register
     * already classified the day as Late. Null otherwise.
     */
    public function lateDurationLabel(TeamActivityAgentRow $agent): ?string
    {
        if ($agent->isVirtual || $agent->minutesLate === null) {
            return null;
        }

        if ($agent->status === TeamActivityStatus::Leave) {
            return null;
        }

        return $agent->minutesLate.'m';
    }

    public function presenceAriaLabel(TeamActivityAgentRow $agent, ?string $latestElapsed): string
    {
        $parts = [$agent->statusLabel];
        $late = $this->lateDurationLabel($agent);
        $secondary = $this->secondaryContextLabel($agent, $latestElapsed);

        if ($late !== null) {
            $parts[] = 'L'.$late;
        }

        if ($secondary !== null) {
            $parts[] = $secondary;
        }

        return implode(' · ', $parts);
    }

    public function ariaLabel(TeamActivityAgentRow $agent, ?string $latestElapsed): string
    {
        return $this->presenceAriaLabel($agent, $latestElapsed);
    }

    public function normalizeDuration(?string $value): ?string
    {
        if ($value === null || $value === '' || $value === '—' || $value === '0m') {
            return null;
        }

        $compact = $this->durationPresenter->compact($value);

        return $this->durationPresenter->isDuration($compact) ? $compact : $value;
    }
}
