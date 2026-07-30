<?php

namespace App\Services\Workforce\Contribution\Signals;

use App\Contracts\Workforce\ContributionSignalCollector;
use App\Data\Workforce\Contribution\ContributionSignal;
use App\Enums\ContributionSignalId;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Reserved signal adapter — returns unavailable zero until instrumentation exists.
 */
final class ReservedSignalCollector implements ContributionSignalCollector
{
    public function __construct(
        private readonly ContributionSignalId $signalId,
        private readonly string $note = 'Reserved — not instrumented yet',
    ) {}

    public function id(): ContributionSignalId
    {
        return $this->signalId;
    }

    public function collect(User $user, Carbon $workDate, Collection $sessions): ContributionSignal
    {
        return new ContributionSignal(
            id: $this->signalId,
            value: 0,
            unit: $this->signalId->unit(),
            available: false,
            reserved: true,
            note: $this->note,
        );
    }
}
