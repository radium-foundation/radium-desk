<?php

namespace App\Services\Workforce\Contribution\Signals;

use App\Contracts\Workforce\ContributionSignalCollector;
use App\Data\Workforce\Contribution\ContributionSignal;
use App\Enums\ContributionSignalId;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

abstract class AbstractSessionSumSignalCollector implements ContributionSignalCollector
{
    abstract protected function column(): string;

    public function collect(User $user, Carbon $workDate, Collection $sessions): ContributionSignal
    {
        $value = (int) $sessions->sum($this->column());

        return new ContributionSignal(
            id: $this->id(),
            value: $value,
            unit: $this->id()->unit(),
            available: true,
            reserved: false,
        );
    }
}
