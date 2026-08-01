<?php

namespace App\Services\Workforce\Recognition;

use App\Contracts\Workforce\IncentivePolicy;
use App\Data\Workforce\Recognition\RecognitionAward;
use App\Enums\RecognitionRecommendation;
use App\Enums\RecognitionReviewStatus;
use App\Models\WorkRecognitionReview;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Payroll consumer port — approved Work Recognition awards only.
 * Does not read or write Attendance. Recognition Extra labels are not Attendance Extra.
 */
class ConfigIncentivePolicy implements IncentivePolicy
{
    /**
     * @return Collection<int, RecognitionAward>
     */
    public function approvedAwardsForMonth(Carbon $month): Collection
    {
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();

        return WorkRecognitionReview::query()
            ->where('status', RecognitionReviewStatus::Decided)
            ->whereNotNull('decision')
            ->where('decision', '!=', RecognitionRecommendation::NoBenefit->value)
            ->whereDate('work_date', '>=', $start->toDateString())
            ->whereDate('work_date', '<=', $end->toDateString())
            ->orderBy('work_date')
            ->get()
            ->map(fn (WorkRecognitionReview $review): RecognitionAward => new RecognitionAward(
                reviewId: (int) $review->id,
                userId: (int) $review->user_id,
                workDate: $review->work_date->copy()->startOfDay(),
                decision: $review->decision,
                departmentPack: (string) $review->department_pack,
                reason: $review->decision_reason,
                decidedAt: $review->decided_at?->copy(),
            ));
    }
}
