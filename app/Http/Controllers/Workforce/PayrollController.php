<?php

namespace App\Http\Controllers\Workforce;

use App\Http\Controllers\Controller;
use App\Http\Requests\FinalizePayrollMonthRequest;
use App\Models\User;
use App\Services\Workforce\Payroll\PayrollRunService;
use App\Services\Workforce\PayrollMonthLockService;
use App\Services\Workforce\ShortAttendance\ShortAttendanceReviewService;
use App\Support\Workforce\AttendanceManagementAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class PayrollController extends Controller
{
    public function __construct(
        private readonly PayrollRunService $payrollRunService,
        private readonly PayrollMonthLockService $payrollMonthLockService,
        private readonly ShortAttendanceReviewService $shortAttendanceReviewService,
    ) {
        $this->middleware(function ($request, $next) {
            abort_unless(AttendanceManagementAccess::allowsPayroll($request->user()), 403);

            return $next($request);
        });
    }

    public function index(Request $request): View
    {
        $month = $this->resolveMonth($request->query('month'));
        $run = $this->payrollRunService->loadFinalized($month);
        $isFinalized = $run !== null;

        $this->shortAttendanceReviewService->syncPendingForMonth($month);
        $pendingShortAttendance = $this->shortAttendanceReviewService->pendingCount($month);

        return view('workforce-management.payroll.index', [
            'monthValue' => $month->format('Y-m'),
            'monthLabel' => $month->format('F Y'),
            'rows' => $this->payrollRunService->resultsForMonth($month),
            'payrollLock' => $this->payrollMonthLockService->statusForMonth($month),
            'isFinalized' => $isFinalized,
            'payrollRun' => $run,
            'canFinalizePayroll' => AttendanceManagementAccess::allowsPayroll($request->user()),
            'pendingShortAttendanceCount' => $pendingShortAttendance,
        ]);
    }

    public function show(Request $request, User $user): View
    {
        $month = $this->resolveMonth($request->query('month'));
        $result = $this->payrollRunService->resultForUser($user, $month);

        abort_if($result === null, 404, 'No payroll data found for this employee in the selected month.');

        $run = $this->payrollRunService->loadFinalized($month);

        return view('workforce-management.payroll.show', [
            'monthValue' => $month->format('Y-m'),
            'monthLabel' => $month->format('F Y'),
            'result' => $result,
            'payrollLock' => $this->payrollMonthLockService->statusForMonth($month),
            'isFinalized' => $run !== null,
            'payrollRun' => $run,
            'user' => $user,
        ]);
    }

    public function finalize(FinalizePayrollMonthRequest $request): RedirectResponse
    {
        $month = Carbon::createFromFormat('Y-m', (string) $request->validated('month'))->startOfMonth();

        $this->payrollRunService->finalize(
            $month,
            $request->user(),
            $request->validated('notes'),
        );

        return redirect()
            ->route('workforce-management.payroll.index', ['month' => $month->format('Y-m')])
            ->with('status', 'payroll-month-finalized');
    }

    private function resolveMonth(mixed $month): Carbon
    {
        if (is_string($month) && preg_match('/^\d{4}-\d{2}$/', $month) === 1) {
            return Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        }

        return now()->copy()->startOfMonth();
    }
}
