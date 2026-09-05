<?php

namespace App\Services\StatutoryInvoice\Data;

use App\Services\StatutoryInvoice\StatutoryMintEligibility;

final class StatutoryMintEligibilityResult
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(
        public readonly bool $eligible,
        public readonly array $errors = [],
    ) {}

    public function missingPlaceOfSupply(): bool
    {
        return in_array(StatutoryMintEligibility::PLACE_OF_SUPPLY_MISSING, $this->errors, true);
    }

    public function staffSummary(): string
    {
        if ($this->eligible) {
            return 'Ready to issue';
        }

        if ($this->missingPlaceOfSupply()) {
            return 'Place of supply missing';
        }

        return 'Missing statutory data';
    }
}
