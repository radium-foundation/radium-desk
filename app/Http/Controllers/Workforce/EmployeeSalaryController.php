<?php

namespace App\Http\Controllers\Workforce;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReviseEmployeeSalaryRequest;
use App\Http\Requests\StoreEmployeeSalaryRequest;
use App\Models\EmployeeSalary;
use App\Models\User;
use App\Services\Operations\OperationsRoleService;
use App\Services\Workforce\Payroll\EmployeeSalaryService;
use App\Support\Workforce\AttendanceManagementAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EmployeeSalaryController extends Controller
{
    public function __construct(
        private readonly EmployeeSalaryService $employeeSalaryService,
        private readonly OperationsRoleService $roleService,
    ) {
        $this->middleware(function ($request, $next) {
            abort_unless(AttendanceManagementAccess::allows($request->user()), 403);

            return $next($request);
        });
    }

    public function index(Request $request): View
    {
        $employees = User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->whereIn('name', $this->roleService->attendanceTrackedRoleSlugs()))
            ->orderBy('name')
            ->get()
            ->filter(fn (User $user): bool => $this->roleService->isAttendanceTracked($user))
            ->values();

        return view('workforce-management.salaries.index', [
            'salaries' => $this->employeeSalaryService->listAll(),
            'employees' => $employees,
        ]);
    }

    public function store(StoreEmployeeSalaryRequest $request): RedirectResponse
    {
        $this->employeeSalaryService->create($request->validated());

        return redirect()
            ->route('workforce-management.salaries.index')
            ->with('status', 'employee-salary-created');
    }

    /**
     * Append-only: creates a new revision; never mutates $salary.
     */
    public function revise(ReviseEmployeeSalaryRequest $request, EmployeeSalary $salary): RedirectResponse
    {
        $this->employeeSalaryService->revise($salary, $request->validated());

        return redirect()
            ->route('workforce-management.salaries.index')
            ->with('status', 'employee-salary-revised');
    }
}
