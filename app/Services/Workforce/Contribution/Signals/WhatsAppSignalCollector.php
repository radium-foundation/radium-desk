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
 * Adapts manual WhatsApp template sends (SupportActivityMetricsService source).
 */
final class WhatsAppSignalCollector implements ContributionSignalCollector
{
    public function __construct(
        private readonly ContributionActivityQuery $activityQuery,
    ) {}

    public function id(): ContributionSignalId
    {
        return ContributionSignalId::Whatsapp;
    }

    public function collect(User $user, Carbon $workDate, Collection $sessions): ContributionSignal
    {
        return new ContributionSignal(
            id: $this->id(),
            value: $this->activityQuery->countManualWhatsApp((int) $user->id, $workDate),
            unit: $this->id()->unit(),
            available: true,
            note: 'Source: audit_logs whatsapp.template_sent (manual)',
        );
    }
}
