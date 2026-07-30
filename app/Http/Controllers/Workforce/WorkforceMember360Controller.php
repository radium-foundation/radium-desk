<?php

namespace App\Http\Controllers\Workforce;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Operations\OperationsRoleService;
use App\Services\Workforce\WorkforceMember360Service;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class WorkforceMember360Controller extends Controller
{
    public function __construct(
        private readonly WorkforceMember360Service $member360Service,
        private readonly OperationsRoleService $roleService,
    ) {
        $this->middleware(function ($request, $next) {
            abort_unless($request->user()?->can('team-performance.view'), 403);

            return $next($request);
        });
    }

    public function show(Request $request, User $user): View
    {
        abort_unless($this->roleService->isAttendanceTracked($user), 404);

        $month = $this->resolveMonth($request->query('month'));
        $day = is_string($request->query('day')) ? $request->query('day') : null;
        $profile = $this->member360Service->build($user, $month, $day);

        return view('workforce-management.member-360.drawer-content', [
            'profile' => $profile,
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
