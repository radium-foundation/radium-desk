<?php

namespace App\Data\Operations;

use Illuminate\Support\Carbon;

final class LeavePolicyEvaluation
{
    /**
     * @param  list<string>  $sandwichSundayDates  Y-m-d
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly Carbon $requestedStart,
        public readonly Carbon $requestedEnd,
        public readonly Carbon $effectiveStart,
        public readonly Carbon $effectiveEnd,
        public readonly ?int $noticeCalendarDays,
        public readonly bool $sundaySandwichApplied,
        public readonly array $sandwichSundayDates,
        public readonly bool $requiresManualReview,
        public readonly bool $blocksAutoApproval,
        public readonly ?string $blockReason,
        public readonly array $metadata,
    ) {}
}
