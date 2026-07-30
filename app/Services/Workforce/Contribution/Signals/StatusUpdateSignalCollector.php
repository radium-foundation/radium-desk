<?php

namespace App\Services\Workforce\Contribution\Signals;

use App\Contracts\Workforce\ContributionSignalCollector;
use App\Data\Workforce\Contribution\ContributionSignal;
use App\Enums\ContributionSignalId;
use App\Models\User;
use App\Services\Workforce\Contribution\ContributionActivityQuery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class StatusUpdateSignalCollector implements ContributionSignalCollector
{
    public function __construct(
        private readonly ContributionActivityQuery $activityQuery,
    ) {}

    public function id(): ContributionSignalId
    {
        return ContributionSignalId::StatusUpdates;
    }

    public function collect(User $user, Carbon $workDate, Collection $sessions): ContributionSignal
    {
        $events = config('operations-kpi.support.effort_events.status_updates', [
            'service_case.status_changed',
        ]);

        return new ContributionSignal(
            id: $this->id(),
            value: $this->activityQuery->countAuditEvents((int) $user->id, $workDate, $events),
            unit: $this->id()->unit(),
            available: true,
            note: 'Source: audit_logs status update events',
        );
    }
}
