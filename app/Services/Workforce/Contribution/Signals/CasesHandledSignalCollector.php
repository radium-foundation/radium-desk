<?php

namespace App\Services\Workforce\Contribution\Signals;

/**
 * @deprecated Use CaseSignalCollector. Kept for backward-compatible class references.
 */
final class CasesHandledSignalCollector extends AbstractSessionSumSignalCollector
{
    public function id(): \App\Enums\ContributionSignalId
    {
        return \App\Enums\ContributionSignalId::CasesHandled;
    }

    protected function column(): string
    {
        return 'cases_handled_count';
    }
}
