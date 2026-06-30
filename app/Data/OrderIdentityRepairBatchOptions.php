<?php

namespace App\Data;

readonly class OrderIdentityRepairBatchOptions
{
    public function __construct(
        public ?int $limit = null,
        public int $offset = 0,
        public bool $dryRun = false,
        public bool $activeOnly = false,
        public bool $resume = false,
    ) {}
}
