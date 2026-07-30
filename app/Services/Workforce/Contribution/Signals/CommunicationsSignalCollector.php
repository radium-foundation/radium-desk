<?php

namespace App\Services\Workforce\Contribution\Signals;

use App\Enums\ContributionSignalId;

/**
 * Exposes existing work_sessions.communication_events_count (combined channel metric).
 * Per-channel Email / WhatsApp / Calls remain reserved until instrumented separately.
 */
final class CommunicationsSignalCollector extends AbstractSessionSumSignalCollector
{
    public function id(): ContributionSignalId
    {
        return ContributionSignalId::Communications;
    }

    protected function column(): string
    {
        return 'communication_events_count';
    }
}
