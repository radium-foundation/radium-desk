<?php

namespace App\Services\Workforce\Recognition;

use App\Enums\RecognitionRecommendation;
use App\Enums\RecognitionReviewStatus;
use App\Models\User;
use App\Models\WorkRecognitionReview;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class WorkRecognitionQueryService
{
    /**
     * @param  array{
     *     month?: string|null,
     *     status?: string|null,
     *     day_context?: string|null,
     *     department_pack?: string|null,
     *     user_id?: int|null,
     *     ui_status?: string|null
     * }  $filters
     * @return array{pending: int, approved: int, rejected: int}
     */
    public function summaryCounts(array $filters = []): array
    {
        $base = $this->filteredQuery($filters);

        $pending = (clone $base)->where('status', RecognitionReviewStatus::PendingReview)->count();
        $approved = (clone $base)
            ->where('status', RecognitionReviewStatus::Decided)
            ->where('decision', '!=', RecognitionRecommendation::NoBenefit->value)
            ->whereNotNull('decision')
            ->count();
        $rejected = (clone $base)
            ->where('status', RecognitionReviewStatus::Decided)
            ->where('decision', RecognitionRecommendation::NoBenefit->value)
            ->count();

        return compact('pending', 'approved', 'rejected');
    }

    /**
     * @param  array{
     *     month?: string|null,
     *     status?: string|null,
     *     day_context?: string|null,
     *     department_pack?: string|null,
     *     user_id?: int|null,
     *     ui_status?: string|null
     * }  $filters
     */
    public function paginate(array $filters = [], int $perPage = 50): LengthAwarePaginator
    {
        return $this->filteredQuery($filters)
            ->with(['user', 'decider'])
            ->orderByDesc('work_date')
            ->orderBy('user_id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @return Collection<int, User>
     */
    public function filterableEmployees(): Collection
    {
        return User::query()
            ->whereIn('id', WorkRecognitionReview::query()->select('user_id'))
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'name', 'first_name', 'last_name']);
    }

    /**
     * @param  array{
     *     month?: string|null,
     *     status?: string|null,
     *     day_context?: string|null,
     *     department_pack?: string|null,
     *     user_id?: int|null,
     *     ui_status?: string|null
     * }  $filters
     */
    private function filteredQuery(array $filters)
    {
        $query = WorkRecognitionReview::query();

        $month = $filters['month'] ?? null;
        if (is_string($month) && preg_match('/^\d{4}-\d{2}$/', $month) === 1) {
            $start = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
            $end = $start->copy()->endOfMonth();
            $query->whereDate('work_date', '>=', $start->toDateString())
                ->whereDate('work_date', '<=', $end->toDateString());
        }

        if (! empty($filters['day_context'])) {
            $query->where('day_context', $filters['day_context']);
        }

        if (! empty($filters['department_pack'])) {
            $query->where('department_pack', $filters['department_pack']);
        }

        if (! empty($filters['user_id'])) {
            $query->where('user_id', (int) $filters['user_id']);
        }

        $uiStatus = $filters['ui_status'] ?? $filters['status'] ?? null;
        if ($uiStatus === 'pending') {
            $query->where('status', RecognitionReviewStatus::PendingReview);
        } elseif ($uiStatus === 'approved') {
            $query->where('status', RecognitionReviewStatus::Decided)
                ->whereNotNull('decision')
                ->where('decision', '!=', RecognitionRecommendation::NoBenefit->value);
        } elseif ($uiStatus === 'rejected') {
            $query->where('status', RecognitionReviewStatus::Decided)
                ->where('decision', RecognitionRecommendation::NoBenefit->value);
        }

        return $query;
    }
}
