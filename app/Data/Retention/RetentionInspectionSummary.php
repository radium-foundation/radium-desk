<?php

namespace App\Data\Retention;

use Illuminate\Support\Carbon;

readonly class RetentionInspectionSummary
{
    /**
     * @param  list<RetentionCategorySummary>  $categories
     */
    public function __construct(
        public Carbon $inspectedAt,
        public array $categories,
        public int $totalCandidates,
    ) {}
}
