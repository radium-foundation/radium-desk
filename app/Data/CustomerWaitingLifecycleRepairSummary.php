<?php

namespace App\Data;

readonly class CustomerWaitingLifecycleRepairSummary
{
    /**
     * @param  array<string, int>  $counts
     * @param  list<array<string, mixed>>  $samples
     */
    public function __construct(
        public bool $dryRun,
        public array $counts,
        public array $samples = [],
        public ?string $configurationError = null,
    ) {}
}
