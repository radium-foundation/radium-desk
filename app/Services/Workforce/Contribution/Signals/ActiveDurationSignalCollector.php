<?php

namespace App\Services\Workforce\Contribution\Signals;

use App\Enums\ContributionSignalId;

final class ActiveDurationSignalCollector extends AbstractSessionSumSignalCollector
{
    public function id(): ContributionSignalId
    {
        return ContributionSignalId::ActiveDuration;
    }

    protected function column(): string
    {
        return 'active_duration_seconds';
    }
}
