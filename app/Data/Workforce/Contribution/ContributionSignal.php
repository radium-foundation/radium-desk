<?php

namespace App\Data\Workforce\Contribution;

use App\Enums\ContributionSignalId;

/**
 * Single collected signal value for a user/day.
 */
readonly class ContributionSignal
{
    public function __construct(
        public ContributionSignalId $id,
        public int|float $value,
        public string $unit,
        public bool $available,
        public bool $reserved = false,
        public ?string $note = null,
    ) {}

    public function label(): string
    {
        return $this->id->label();
    }
}
