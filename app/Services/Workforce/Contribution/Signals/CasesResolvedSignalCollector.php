<?php

namespace App\Services\Workforce\Contribution\Signals;

use App\Enums\ContributionSignalId;

final class CasesResolvedSignalCollector extends AbstractSessionSumSignalCollector
{
    public function id(): ContributionSignalId
    {
        return ContributionSignalId::CasesResolved;
    }

    protected function column(): string
    {
        return 'resolution_events_count';
    }
}
