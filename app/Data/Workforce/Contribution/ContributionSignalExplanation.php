<?php

namespace App\Data\Workforce\Contribution;

use App\Enums\ContributionSignalId;

/**
 * Per-signal explainability row for Employee 360 / AI (Rule Book §5).
 */
readonly class ContributionSignalExplanation
{
    public function __construct(
        public ContributionSignalId $signal,
        public int|float $observedValue,
        public int|float|null $normalThreshold,
        public int|float|null $highThreshold,
        public bool $qualified,
        public string $level,
        public string $reason,
        public bool $available = true,
        public bool $reserved = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'signal' => $this->signal->value,
            'label' => $this->signal->label(),
            'observed_value' => $this->observedValue,
            'normal_threshold' => $this->normalThreshold,
            'high_threshold' => $this->highThreshold,
            'qualified' => $this->qualified,
            'level' => $this->level,
            'reason' => $this->reason,
            'available' => $this->available,
            'reserved' => $this->reserved,
        ];
    }
}
