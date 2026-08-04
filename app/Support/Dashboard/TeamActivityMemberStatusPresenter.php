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
     * Compact operational status code (A, I, P, ALO, …).
     */
    public function statusCode(TeamActivityAgentRow $agent): string
    {
        return TeamActivityPresenceLegend::codeFor($agent->status);
    }

    /**
     * Duration merged into the status code as a superscript (e.g. 1h 34m → A¹ʰ³⁴ᵐ).
     * Null when the status has no state duration (LV, NLI, SNS, NS, …).
     */
    public function stateDurationLabel(TeamActivityAgentRow $agent, ?string $latestElapsed): ?string
    {
        if ($agent->isVirtual) {
            return $this->normalizeDuration($latestElapsed);
        }

        return match ($agent->status) {
            TeamActivityStatus::Working,
            TeamActivityStatus::Idle,
            TeamActivityStatus::Break,
            TeamActivityStatus::Login,
            TeamActivityStatus::Assignment,
            TeamActivityStatus::Remark,
            TeamActivityStatus::StatusChanged,
            TeamActivityStatus::SerialUpdated,
            TeamActivityStatus::ModelUpdated,
            TeamActivityStatus::Refund,
            TeamActivityStatus::Approval => $this->normalizeDuration($agent->currentDurationLabel)
                ?? $this->normalizeDuration($latestElapsed),
            TeamActivityStatus::WaitingCustomer,
            TeamActivityStatus::OnIvr,
            TeamActivityStatus::Email,
            TeamActivityStatus::Whatsapp,
            TeamActivityStatus::Ira => $this->normalizeDuration($latestElapsed)
                ?? $this->normalizeDuration($agent->currentDurationLabel),
            TeamActivityStatus::AutoLogout => $this->normalizeDuration($agent->currentDurationLabel)
                ?? $this->normalizeDuration($latestElapsed),
            default => null,
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

    /**
     * Accessible label: full words + durations (not the compact visual codes).
     */
    public function presenceAriaLabel(TeamActivityAgentRow $agent, ?string $latestElapsed): string
    {
        $parts = [$agent->statusLabel];
        $duration = $this->stateDurationLabel($agent, $latestElapsed);
        $late = $this->lateDurationLabel($agent);

        if ($agent->status === TeamActivityStatus::Leave && filled($agent->workingLabel)) {
            $parts[] = $agent->workingLabel;
        } elseif ($duration !== null) {
            $parts[] = $duration;
        }

        if ($late !== null) {
            $parts[] = 'Late '.$late;
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
