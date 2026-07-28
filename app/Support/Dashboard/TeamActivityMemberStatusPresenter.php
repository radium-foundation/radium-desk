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

    public function ariaLabel(TeamActivityAgentRow $agent, ?string $latestElapsed): string
    {
        $context = $this->contextLabel($agent, $latestElapsed);

        if ($context === null) {
            return $agent->statusLabel;
        }

        return $agent->statusLabel.' · '.$context;
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
