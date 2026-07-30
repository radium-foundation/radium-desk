<?php

namespace App\Http\Controllers\Workforce;

use App\Http\Controllers\Controller;
use App\Services\Workforce\MonthlyAttendanceMatrixService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class MonthlyAttendanceController extends Controller
{
    public function __construct(
        private readonly MonthlyAttendanceMatrixService $matrixService,
    ) {
        $this->middleware(function ($request, $next) {
            abort_unless($request->user()?->can('team-performance.view'), 403);

            return $next($request);
        });
    }

    public function index(Request $request): View
    {
        $month = $this->resolveMonth($request->query('month'));

        return view('workforce-management.attendance.index', [
            'report' => $this->matrixService->build($month),
            'monthValue' => $month->format('Y-m'),
        ]);
    }

    private function resolveMonth(mixed $month): Carbon
    {
        if (is_string($month) && preg_match('/^\d{4}-\d{2}$/', $month) === 1) {
            return Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        }

        return now()->copy()->startOfMonth();
    }
}
