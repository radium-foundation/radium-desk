<?php

namespace App\Data\Workforce\Contribution;

use App\Enums\ContributionVerdict;
use Illuminate\Support\Carbon;

/**
 * Result of ContributionEngine::evaluate — never mutates Attendance.
 */
readonly class ContributionEvaluation
{
    /**
     * @param  list<ContributionSignal>  $signals
     * @param  list<ContributionSignalExplanation>  $explanations
     * @param  list<string>  $reasons
     * @param  list<string>  $thresholdsMet
     * @param  list<string>  $thresholdsFailed
     */
    public function __construct(
        public int $userId,
        public Carbon $workDate,
        public ContributionPack $pack,
        public ContributionVerdict $verdict,
        public array $signals,
        public array $reasons,
        public array $thresholdsMet,
        public array $thresholdsFailed,
        public bool $engineEnabled,
        public ContributionSnapshot $snapshot,
        public array $explanations = [],
    ) {}

    public function isQualified(): bool
    {
        return $this->engineEnabled && $this->verdict->isQualified();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function explanationPayload(): array
    {
        return array_map(
            static fn (ContributionSignalExplanation $row): array => $row->toArray(),
            $this->explanations,
        );
    }
}
