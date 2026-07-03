<?php

namespace App\Data\RadiumBox;

readonly class RadiumBoxSyncRecoveryResult
{
    /**
     * @param  list<int>  $recoveredOrderIds
     * @param  list<int>  $skippedOrderIds
     */
    public function __construct(
        public int $scanned,
        public int $recovered,
        public int $skipped,
        public array $recoveredOrderIds = [],
        public array $skippedOrderIds = [],
    ) {}
}
