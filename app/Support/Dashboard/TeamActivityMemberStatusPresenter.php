<?php

namespace App\Support\Dashboard;

use App\Data\TeamActivityAgentRow;
use App\Enums\TeamActivityStatus;

class TeamActivityMemberStatusPresenter
{
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

        if (preg_match('/^\d+ (sec|min|hr)$/', $value) === 1) {
            return $value;
        }

        if (preg_match('/^(\d+)m$/', $value, $matches) === 1) {
            return $matches[1].' min';
        }

        if (preg_match('/^(\d+)h (\d+)m$/', $value, $matches) === 1) {
            return $matches[2] === '0'
                ? $matches[1].' hr'
                : $matches[1].' hr '.$matches[2].' min';
        }

        if (preg_match('/^(\d+)h$/', $value, $matches) === 1) {
            return $matches[1].' hr';
        }

        return $value;
    }
}
