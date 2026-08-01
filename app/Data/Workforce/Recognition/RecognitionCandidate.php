<?php

namespace App\Data\Workforce\Recognition;

use App\Enums\RecognitionDayContext;
use App\Enums\RecognitionRecommendation;
use Illuminate\Support\Carbon;

readonly class RecognitionCandidate
{
    public function __construct(
        public int $userId,
        public Carbon $workDate,
        public RecognitionDayContext $dayContext,
    ) {}
}
