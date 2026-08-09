<?php

namespace App\Data;

readonly class CashfreeMissedBatchHealResult
{
    /**
     * @param  list<CashfreeMissedBatchHealOrderResult>  $orders
     */
    public function __construct(
        public bool $dryRun,
        public array $orders,
        public int $wouldHeal = 0,
        public int $healed = 0,
        public int $resumed = 0,
        public int $skipped = 0,
        public int $blocked = 0,
        public int $failed = 0,
    ) {}
}
