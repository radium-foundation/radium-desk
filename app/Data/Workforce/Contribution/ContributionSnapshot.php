<?php

namespace App\Data\Workforce\Contribution;

use App\Enums\ContributionVerdict;
use Illuminate\Support\Carbon;

/**
 * Readonly daily contribution snapshot (DTO only — no table).
 */
readonly class ContributionSnapshot
{
    /**
     * @param  list<ContributionSignal>  $signals
     */
    public function __construct(
        public int $userId,
        public Carbon $workDate,
        public ContributionPack $pack,
        public array $signals,
        public int $sessionCount,
        public int $activeDurationSeconds,
        public bool $engineEnabled,
    ) {}
}
