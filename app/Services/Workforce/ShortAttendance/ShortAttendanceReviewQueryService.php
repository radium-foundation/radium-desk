<?php

namespace App\Services\Workforce\ShortAttendance;

use App\Enums\ShortAttendanceReviewStatus;
use App\Models\User;
use App\Models\WorkforceShortAttendanceReview;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ShortAttendanceReviewQueryService
{
    public const PERIOD_TODAY = 'today';

    public const PERIOD_YESTERDAY = 'yesterday';

    public const PERIOD_LAST_7_DAYS = 'last_7_days';

    public const PERIOD_THIS_MONTH = 'this_month';

    /**
     * @return list<string>
     */
    public static function periodValues(): array
    {
        return [
            self::PERIOD_TODAY,
            self::PERIOD_YESTERDAY,
            self::PERIOD_LAST_7_DAYS,
            self::PERIOD_THIS_MONTH,
        ];
    }

    public function __construct(
        private readonly ShortAttendanceReviewService $reviewService,
    ) {}

    /**
     * @param  array{period?: string, ui_status?: ?string, user_id?: ?string, month?: ?string}  $filters
     * @return array{
     *     pending: int,
     *     decided: int,
     *     total: int,
     *     pending_today: int,
     *     pending_yesterday: int,
     *     pending_total: int
     * }
     */
    public function summaryCounts(array $filters): array
    {
        [$from, $to] = $this->dateRangeFromFilters($filters);
        $this->reviewService->syncPendingForRange($from, $to);

        $base = $this->filteredQuery($filters);

        $pending = (clone $base)
            ->where('status', ShortAttendanceReviewStatus::PendingReview)
            ->count();
        $decided = (clone $base)
            ->where('status', ShortAttendanceReviewStatus::Decided)
            ->count();

        $dashboard = $this->reviewService->dashboardPendingCounts();

        return [
            'pending' => $pending,
            'decided' => $decided,
            'total' => $pending + $decided,
            'pending_today' => $dashboard['today'],
            'pending_yesterday' => $dashboard['yesterday'],
            'pending_total' => $dashboard['total'],
        ];
    }

    /**
     * @param  array{period?: string, ui_status?: ?string, user_id?: ?string, month?: ?string}  $filters
     */
    public function paginate(array $filters, int $perPage = 50): LengthAwarePaginator
    {
        [$from, $to] = $this->dateRangeFromFilters($filters);
        $this->reviewService->syncPendingForRange($from, $to);

        return $this->orderedQuery($filters)
            ->with(['user', 'decider'])
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Adjacent reviews in the same queue order for keyboard Next/Previous.
     *
     * @param  array{period?: string, ui_status?: ?string, user_id?: ?string, month?: ?string}  $filters
     * @return array{previous: ?WorkforceShortAttendanceReview, next: ?WorkforceShortAttendanceReview}
     */
    public function adjacent(WorkforceShortAttendanceReview $current, array $filters): array
    {
        $ids = $this->orderedQuery($filters)->pluck('id')->all();
        $index = array_search($current->id, $ids, true);

        if ($index === false) {
            return ['previous' => null, 'next' => null];
        }

        $previousId = $ids[$index - 1] ?? null;
        $nextId = $ids[$index + 1] ?? null;

        return [
            'previous' => $previousId
                ? WorkforceShortAttendanceReview::query()->with('user')->find($previousId)
                : null,
            'next' => $nextId
                ? WorkforceShortAttendanceReview::query()->with('user')->find($nextId)
                : null,
        ];
    }

    /**
     * Next pending case after a decision (same filters, skip decided current).
     *
     * @param  array{period?: string, ui_status?: ?string, user_id?: ?string, month?: ?string}  $filters
     */
    public function nextPendingAfter(
        WorkforceShortAttendanceReview $current,
        array $filters,
    ): ?WorkforceShortAttendanceReview {
        $pendingFilters = [...$filters, 'ui_status' => 'pending'];
        $adjacent = $this->adjacent($current, $pendingFilters);

        if ($adjacent['next'] !== null) {
            return $adjacent['next'];
        }

        // Current may have left the pending list — return first remaining pending.
        return $this->orderedQuery($pendingFilters)
            ->with('user')
            ->first();
    }

    /**
     * @return Collection<int, User>
     */
    public function filterableEmployees(): Collection
    {
        return User::query()
            ->whereIn('id', WorkforceShortAttendanceReview::query()->select('user_id'))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * @param  array{period?: string, ui_status?: ?string, user_id?: ?string, month?: ?string}  $filters
     */
    public function normalizeFilters(array $filters): array
    {
        $period = (string) ($filters['period'] ?? self::PERIOD_TODAY);
        if (! in_array($period, self::periodValues(), true)) {
            $period = self::PERIOD_TODAY;
        }

        $uiStatus = $filters['ui_status'] ?? 'pending';
        if (! in_array($uiStatus, ['pending', 'decided', ''], true) && $uiStatus !== null) {
            $uiStatus = 'pending';
        }

        $month = now()->format('Y-m');
        $monthInput = $filters['month'] ?? null;
        if (is_string($monthInput) && preg_match('/^\d{4}-\d{2}$/', $monthInput) === 1) {
            $month = $monthInput;
        }

        return [
            'period' => $period,
            'ui_status' => $uiStatus === null ? 'pending' : (string) $uiStatus,
            'user_id' => filled($filters['user_id'] ?? null) ? (string) $filters['user_id'] : '',
            'month' => $month,
        ];
    }

    /**
     * @param  array{period?: string, ui_status?: ?string, user_id?: ?string, month?: ?string}  $filters
     * @return array{0: Carbon, 1: Carbon}
     */
    public function dateRangeFromFilters(array $filters): array
    {
        $normalized = $this->normalizeFilters($filters);
        $period = $normalized['period'];
        $today = now()->copy()->startOfDay();

        return match ($period) {
            self::PERIOD_YESTERDAY => [
                $today->copy()->subDay(),
                $today->copy()->subDay(),
            ],
            self::PERIOD_LAST_7_DAYS => [
                $today->copy()->subDays(6),
                $today->copy(),
            ],
            self::PERIOD_THIS_MONTH => [
                Carbon::createFromFormat('Y-m', $normalized['month'])->startOfMonth(),
                Carbon::createFromFormat('Y-m', $normalized['month'])->endOfMonth()->startOfDay(),
            ],
            default => [$today->copy(), $today->copy()],
        };
    }

    /**
     * @param  array{period?: string, ui_status?: ?string, user_id?: ?string, month?: ?string}  $filters
     */
    private function orderedQuery(array $filters): Builder
    {
        // Oldest Pending First: pending before decided, then oldest work_date.
        return $this->filteredQuery($filters)
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [
                ShortAttendanceReviewStatus::PendingReview->value,
            ])
            ->orderBy('work_date')
            ->orderBy('user_id')
            ->orderBy('id');
    }

    /**
     * @param  array{period?: string, ui_status?: ?string, user_id?: ?string, month?: ?string}  $filters
     */
    private function filteredQuery(array $filters): Builder
    {
        $filters = $this->normalizeFilters($filters);
        [$from, $to] = $this->dateRangeFromFilters($filters);

        $query = WorkforceShortAttendanceReview::query()
            ->whereDate('work_date', '>=', $from->toDateString())
            ->whereDate('work_date', '<=', $to->toDateString());

        $uiStatus = $filters['ui_status'];
        if ($uiStatus === 'pending') {
            $query->where('status', ShortAttendanceReviewStatus::PendingReview);
        } elseif ($uiStatus === 'decided') {
            $query->where('status', ShortAttendanceReviewStatus::Decided);
        }

        $userId = $filters['user_id'];
        if ($userId !== '' && ctype_digit($userId)) {
            $query->where('user_id', (int) $userId);
        }

        return $query;
    }
}
