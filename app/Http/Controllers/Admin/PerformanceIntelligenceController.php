<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\PerformanceIntelligence\PerformanceIntelligenceEngine;
use App\Support\Administration\PerformanceIntelligenceAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Super Admin only — Phase 0 shadow review.
 * Never exposed to agents or ordinary admins.
 */
class PerformanceIntelligenceController extends Controller
{
    public function __construct(
        private readonly PerformanceIntelligenceEngine $engine,
    ) {}

    public function index(Request $request): View
    {
        abort_unless(PerformanceIntelligenceAccess::canView($request->user()), 403);

        $dateString = (string) $request->query('date', '');
        $availableDates = $this->engine->availableDates();
        $date = $dateString !== ''
            ? Carbon::parse($dateString, config('app.timezone'))->startOfDay()
            : (
                $availableDates[0] ?? null
                    ? Carbon::parse($availableDates[0], config('app.timezone'))->startOfDay()
                    : now()->subDay()->startOfDay()
            );

        $snapshots = $this->engine->snapshotsForDate($date);

        return view('admin.performance-intelligence.index', [
            'date' => $date,
            'availableDates' => $availableDates,
            'snapshots' => $snapshots,
            'version' => (string) config('performance_intelligence.version', 'phase0.1'),
            'weights' => config('performance_intelligence.weights', []),
        ]);
    }

    public function show(Request $request, int $userId): View
    {
        abort_unless(PerformanceIntelligenceAccess::canView($request->user()), 403);

        $dateString = (string) $request->query('date', now()->subDay()->toDateString());
        $date = Carbon::parse($dateString, config('app.timezone'))->startOfDay();
        $snapshot = $this->engine->snapshotForUser($userId, $date);

        abort_if($snapshot === null, 404);

        return view('admin.performance-intelligence.show', [
            'snapshot' => $snapshot,
            'date' => $date,
            'intuitionNote' => (string) $request->query('intuition', ''),
        ]);
    }

    public function capture(Request $request): RedirectResponse
    {
        abort_unless(PerformanceIntelligenceAccess::canView($request->user()), 403);

        $dateString = (string) $request->input('date', now()->subDay()->toDateString());
        $date = Carbon::parse($dateString, config('app.timezone'))->startOfDay();
        $result = $this->engine->captureDay($date);

        return redirect()
            ->route('admin.performance-intelligence.index', ['date' => $result['date']])
            ->with('status', sprintf(
                'Captured %d snapshots for %s in %d ms.',
                $result['processed'],
                $result['date'],
                $result['duration_ms'],
            ));
    }
}
