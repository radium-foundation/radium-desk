<?php

namespace App\Data\RadiumBox;

final class RadiumBoxHandoffReconciliationResult
{
    /**
     * @param  list<int>  $recoveredOrderIds
     * @param  list<int>  $skippedOrderIds
     */
    public function __construct(
        public readonly int $scanned,
        public readonly int $recovered,
        public readonly int $skipped,
        public readonly array $recoveredOrderIds = [],
        public readonly array $skippedOrderIds = [],
    ) {}
}
