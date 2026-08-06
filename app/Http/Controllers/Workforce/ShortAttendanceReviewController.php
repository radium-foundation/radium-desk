<?php

namespace App\Http\Controllers\Workforce;

use App\Enums\ShortAttendanceReviewDecision;
use App\Http\Controllers\Controller;
use App\Http\Requests\DecideShortAttendanceReviewRequest;
use App\Models\WorkforceShortAttendanceReview;
use App\Services\Workforce\ShortAttendance\ShortAttendanceReviewQueryService;
use App\Services\Workforce\ShortAttendance\ShortAttendanceReviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ShortAttendanceReviewController extends Controller
{
    public function __construct(
        private readonly ShortAttendanceReviewQueryService $queryService,
        private readonly ShortAttendanceReviewService $reviewService,
    ) {
        $this->middleware(function ($request, $next) {
            abort_unless($this->reviewService->canView($request->user()), 403);

            return $next($request);
        });
    }

    public function index(Request $request): View
    {
        $filters = $this->queryService->normalizeFilters([
            'period' => $request->query('period', ShortAttendanceReviewQueryService::PERIOD_TODAY),
            'ui_status' => $request->query('status', 'pending'),
            'user_id' => $request->query('user_id'),
            'month' => $request->query('month'),
        ]);

        return view('workforce-management.short-attendance.index', [
            'filters' => $filters,
            'counts' => $this->queryService->summaryCounts($filters),
            'reviews' => $this->queryService->paginate($filters),
            'employees' => $this->queryService->filterableEmployees(),
            'canDecide' => $this->reviewService->canDecide($request->user()),
            'decisions' => ShortAttendanceReviewDecision::cases(),
            'showMorningReminder' => $this->reviewService->hasYesterdayPendingReminder(),
            'periods' => [
                ShortAttendanceReviewQueryService::PERIOD_TODAY => 'Today',
                ShortAttendanceReviewQueryService::PERIOD_YESTERDAY => 'Yesterday',
                ShortAttendanceReviewQueryService::PERIOD_LAST_7_DAYS => 'Last 7 Days',
                ShortAttendanceReviewQueryService::PERIOD_THIS_MONTH => 'This Month',
            ],
        ]);
    }

    public function show(Request $request, WorkforceShortAttendanceReview $review): View
    {
        $review->load(['user', 'decider']);

        $filters = $this->queryService->normalizeFilters([
            'period' => $request->query('period', ShortAttendanceReviewQueryService::PERIOD_TODAY),
            'ui_status' => $request->query('status', 'pending'),
            'user_id' => $request->query('user_id'),
            'month' => $request->query('month'),
        ]);

        $adjacent = $this->queryService->adjacent($review, $filters);

        return view('workforce-management.short-attendance.show', [
            'review' => $review,
            'canDecide' => $this->reviewService->canDecide($request->user()),
            'decisions' => ShortAttendanceReviewDecision::cases(),
            'filters' => $filters,
            'previousReview' => $adjacent['previous'],
            'nextReview' => $adjacent['next'],
            'showMorningReminder' => $this->reviewService->hasYesterdayPendingReminder(),
        ]);
    }

    public function decide(
        DecideShortAttendanceReviewRequest $request,
        WorkforceShortAttendanceReview $review,
    ): RedirectResponse {
        $decision = ShortAttendanceReviewDecision::from($request->validated('decision'));

        $this->reviewService->decide(
            review: $review,
            actor: $request->user(),
            decision: $decision,
            reason: $request->validated('decision_reason'),
            note: $request->validated('decision_note'),
        );

        $filters = $this->queryService->normalizeFilters([
            'period' => $request->input('period', ShortAttendanceReviewQueryService::PERIOD_TODAY),
            'ui_status' => $request->input('status', 'pending'),
            'user_id' => $request->input('user_id'),
            'month' => $request->input('month'),
        ]);

        $next = $this->queryService->nextPendingAfter($review, $filters);

        if ($next !== null) {
            return redirect()
                ->route('workforce-management.short-attendance.show', [
                    'review' => $next,
                    ...$this->filterQuery($filters),
                ])
                ->with('status', 'short-attendance-reviewed-next');
        }

        return redirect()
            ->route('workforce-management.short-attendance.index', $this->filterQuery($filters))
            ->with('status', 'short-attendance-reviewed');
    }

    /**
     * @param  array{period: string, ui_status: string, user_id: string}  $filters
     * @return array<string, string>
     */
    private function filterQuery(array $filters): array
    {
        $query = [
            'period' => $filters['period'],
            'status' => $filters['ui_status'],
        ];

        if ($filters['period'] === ShortAttendanceReviewQueryService::PERIOD_THIS_MONTH) {
            $query['month'] = $filters['month'];
        }

        if ($filters['user_id'] !== '') {
            $query['user_id'] = $filters['user_id'];
        }

        return $query;
    }
}
