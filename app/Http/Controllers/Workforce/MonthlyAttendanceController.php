<?php

namespace App\Http\Controllers\Workforce;

use App\Http\Controllers\Controller;
use App\Http\Requests\LockPayrollMonthRequest;
use App\Http\Requests\UnlockPayrollMonthRequest;
use App\Services\Workforce\DailyWorkforceEngine;
use App\Services\Workforce\PayrollMonthLockService;
use App\Support\Workforce\AttendanceManagementAccess;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class MonthlyAttendanceController extends Controller
{
    public function __construct(
        private readonly DailyWorkforceEngine $workforceEngine,
        private readonly PayrollMonthLockService $payrollMonthLockService,
    ) {
        $this->middleware(function ($request, $next) {
            // Temporary payroll lock: team-performance.view + allowlist.
            abort_unless(AttendanceManagementAccess::allows($request->user()), 403);

            return $next($request);
        });
    }

    public function index(Request $request): View
    {
        $month = $this->resolveMonth($request->query('month'));
        $user = $request->user();

        return view('workforce-management.attendance.index', [
            'report' => $this->workforceEngine->matrix($month),
            'monthValue' => $month->format('Y-m'),
            'payrollLock' => $this->payrollMonthLockService->statusForMonth($month),
            'canManagePayrollLock' => $user?->hasRole(RolePermissionSeeder::ROLE_SUPERADMIN) ?? false,
        ]);
    }

    public function lock(LockPayrollMonthRequest $request): RedirectResponse
    {
        $month = $this->resolveMonth($request->validated('month'));

        $this->payrollMonthLockService->lock(
            month: $month,
            actor: $request->user(),
            reason: $request->validated('reason'),
        );

        return redirect()
            ->route('workforce-management.attendance.index', ['month' => $month->format('Y-m')])
            ->with('status', 'payroll-month-locked');
    }

    public function unlock(UnlockPayrollMonthRequest $request): RedirectResponse
    {
        $month = $this->resolveMonth($request->validated('month'));

        $this->payrollMonthLockService->unlock(
            month: $month,
            actor: $request->user(),
            reason: $request->validated('reason'),
        );

        return redirect()
            ->route('workforce-management.attendance.index', ['month' => $month->format('Y-m')])
            ->with('status', 'payroll-month-unlocked');
    }

    private function resolveMonth(mixed $month): Carbon
    {
        if (is_string($month) && preg_match('/^\d{4}-\d{2}$/', $month) === 1) {
            return Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        }

        return now()->copy()->startOfMonth();
    }
}
