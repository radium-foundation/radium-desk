<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Enums\WorkSessionEndReason;
use App\Services\Operations\IraAssignmentTelegramBatchService;
use App\Services\Operations\OperationsRoleService;
use App\Services\Operations\PresenceEngineService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    public function __construct(
        private readonly PresenceEngineService $presenceEngine,
        private readonly OperationsRoleService $roleService,
        private readonly IraAssignmentTelegramBatchService $iraAssignmentTelegramBatchService,
    ) {}

    /**
     * Display the login view.
     */
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        $user = $request->user();

        if ($user !== null && $this->roleService->isTeamMember($user)) {
            $this->presenceEngine->startSession($user);
            $this->iraAssignmentTelegramBatchService->flushForUserIfPending($user);
        }

        $home = $user !== null && $user->can('platform-dashboard.view')
            ? route('admin.platform.index', absolute: false)
            : route('dashboard', absolute: false);

        return redirect()->intended($home);
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user !== null && $this->roleService->isTeamMember($user)) {
            $this->presenceEngine->closeSession($user, WorkSessionEndReason::ManualLogout);
        }

        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
