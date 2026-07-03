<?php

namespace App\Data\RadiumBox;

readonly class RadiumBoxBatchRecoveryResult
{
    /**
     * @param  list<int>  $recoveredOrderIds
     * @param  list<int>  $skippedOrderIds
     */
    public function __construct(
        public int $requested,
        public int $recovered,
        public int $skipped,
        public array $recoveredOrderIds = [],
        public array $skippedOrderIds = [],
    ) {}
}
