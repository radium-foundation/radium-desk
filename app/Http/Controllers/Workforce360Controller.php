<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Policies\Workforce360Policy;
use App\Services\Operations\Workforce360Service;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class Workforce360Controller extends Controller
{
    public function __construct(
        private readonly Workforce360Service $workforce360Service,
        private readonly Workforce360Policy $workforce360Policy,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('workforce360.viewTeam');

        return view('workforce.team', [
            'workforce' => $this->workforce360Service->team($request->user()),
            'activeTab' => (string) $request->query('tab', 'overview'),
        ]);
    }

    public function show(Request $request, User $user): View|RedirectResponse
    {
        abort_unless($this->workforce360Policy->viewMember($request->user(), $user), 403);

        if ($request->user()?->id === $user->id) {
            return redirect()->route('my-workforce.index');
        }

        return view('workforce.member', [
            'workforce' => $this->workforce360Service->member($request->user(), $user),
            'activeTab' => (string) $request->query('tab', 'overview'),
        ]);
    }

    public function my(Request $request): View
    {
        Gate::authorize('workforce360.viewSelf');

        $user = $request->user();
        abort_unless($user !== null, 403);

        return view('workforce.member', [
            'workforce' => $this->workforce360Service->member($user, $user),
            'activeTab' => (string) $request->query('tab', 'overview'),
        ]);
    }
}
