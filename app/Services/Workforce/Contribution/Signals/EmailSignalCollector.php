<?php

namespace App\Services\Workforce\Contribution\Signals;

use App\Contracts\Workforce\ContributionSignalCollector;
use App\Data\Workforce\Contribution\ContributionSignal;
use App\Enums\ContributionSignalId;
use App\Models\User;
use App\Services\Workforce\Contribution\ContributionActivityQuery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Adapts Support KPI email effort events from audit_logs (operations-kpi config).
 */
final class EmailSignalCollector implements ContributionSignalCollector
{
    public function __construct(
        private readonly ContributionActivityQuery $activityQuery,
    ) {}

    public function id(): ContributionSignalId
    {
        return ContributionSignalId::Email;
    }

    public function collect(User $user, Carbon $workDate, Collection $sessions): ContributionSignal
    {
        $events = config('operations-kpi.support.effort_events.emails', [
            'notification.dispatched',
            'communication_action.lifecycle',
        ]);

        return new ContributionSignal(
            id: $this->id(),
            value: $this->activityQuery->countAuditEvents((int) $user->id, $workDate, $events),
            unit: $this->id()->unit(),
            available: true,
            note: 'Source: audit_logs email effort events',
        );
    }
}
