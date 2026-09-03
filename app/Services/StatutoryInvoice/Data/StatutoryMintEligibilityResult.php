<?php

namespace App\Services\StatutoryInvoice\Data;

final class StatutoryMintEligibilityResult
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(
        public readonly bool $eligible,
        public readonly array $errors = [],
    ) {}
}
