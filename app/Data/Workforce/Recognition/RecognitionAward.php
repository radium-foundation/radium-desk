<?php

namespace App\Data\Workforce\Recognition;

use App\Enums\RecognitionRecommendation;
use Illuminate\Support\Carbon;

/**
 * Approved recognition award for payroll consumers (not attendance).
 */
readonly class RecognitionAward
{
    public function __construct(
        public int $reviewId,
        public int $userId,
        public Carbon $workDate,
        public RecognitionRecommendation $decision,
        public string $departmentPack,
        public ?string $reason,
        public ?Carbon $decidedAt,
    ) {}
}
