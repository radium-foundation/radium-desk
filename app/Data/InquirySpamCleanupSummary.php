<?php

namespace App\Data;

readonly class InquirySpamCleanupSummary
{
    /**
     * @param  list<string>  $references
     * @param  array<string, int>  $skipReasons
     */
    public function __construct(
        public int $totalFound,
        public int $casesClosed,
        public int $skipped,
        public int $wouldClose = 0,
        public array $references = [],
        public array $skipReasons = [],
    ) {}
}
