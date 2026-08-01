<?php

namespace App\Http\Controllers\Workforce;

use App\Enums\RecognitionDayContext;
use App\Enums\RecognitionRecommendation;
use App\Http\Controllers\Controller;
use App\Http\Requests\DecideWorkRecognitionReviewRequest;
use App\Jobs\ScanWorkRecognitionMonthJob;
use App\Models\WorkRecognitionReview;
use App\Services\Workforce\Recognition\WorkRecognitionQueryService;
use App\Services\Workforce\Recognition\WorkRecognitionReviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WorkRecognitionController extends Controller
{
    public function __construct(
        private readonly WorkRecognitionQueryService $queryService,
        private readonly WorkRecognitionReviewService $reviewService,
    ) {
        $this->middleware(function ($request, $next) {
            abort_unless($request->user()?->can('workforce.recognition.view'), 403);
            abort_unless(config('workforce_recognition.enabled', false), 404);

            return $next($request);
        });
    }

    public function index(Request $request): View
    {
        $monthValue = $this->resolveMonthValue($request->query('month'));
        $filters = [
            'month' => $monthValue,
            'ui_status' => $request->query('status'),
            'day_context' => $request->query('day_context'),
            'department_pack' => $request->query('department_pack'),
            'user_id' => $request->query('user_id'),
        ];

        return view('workforce-management.recognition.index', [
            'monthValue' => $monthValue,
            'filters' => $filters,
            'counts' => $this->queryService->summaryCounts($filters),
            'reviews' => $this->queryService->paginate($filters),
            'employees' => $this->queryService->filterableEmployees(),
            'departmentPacks' => config('workforce_recognition.packs', []),
            'dayContexts' => RecognitionDayContext::cases(),
            'canReview' => $request->user()?->can('workforce.recognition.review') ?? false,
            'canScan' => $request->user()?->can('workforce.recognition.review') ?? false,
            'recommendations' => RecognitionRecommendation::cases(),
        ]);
    }

    public function show(WorkRecognitionReview $review): View
    {
        $review->load(['user', 'decider']);

        return view('workforce-management.recognition.show', [
            'review' => $review,
            'canReview' => request()->user()?->can('workforce.recognition.review') ?? false,
            'recommendations' => RecognitionRecommendation::cases(),
        ]);
    }

    public function decide(
        DecideWorkRecognitionReviewRequest $request,
        WorkRecognitionReview $review,
    ): RedirectResponse {
        $decision = RecognitionRecommendation::from($request->validated('decision'));

        $this->reviewService->decide(
            review: $review,
            actor: $request->user(),
            decision: $decision,
            reason: $request->validated('decision_reason'),
        );

        return redirect()
            ->route('workforce-management.recognition.show', $review)
            ->with('status', 'work-recognition-decided');
    }

    public function refresh(Request $request, WorkRecognitionReview $review): RedirectResponse
    {
        abort_unless($request->user()?->can('workforce.recognition.review'), 403);

        $this->reviewService->refreshPending($review);

        return redirect()
            ->route('workforce-management.recognition.show', $review)
            ->with('status', 'work-recognition-refreshed');
    }

    public function scan(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('workforce.recognition.review'), 403);

        $monthValue = $this->resolveMonthValue($request->input('month', $request->query('month')));

        ScanWorkRecognitionMonthJob::dispatch($monthValue);

        return redirect()
            ->route('workforce-management.recognition.index', ['month' => $monthValue])
            ->with('status', 'work-recognition-scan-queued');
    }

    private function resolveMonthValue(mixed $month): string
    {
        if (is_string($month) && preg_match('/^\d{4}-\d{2}$/', $month) === 1) {
            return $month;
        }

        return now()->format('Y-m');
    }
}
