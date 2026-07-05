<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateTeamWorkScheduleRequest;
use App\Models\User;
use App\Services\Operations\TeamWorkScheduleService;
use Illuminate\Http\RedirectResponse;

class TeamWorkScheduleController extends Controller
{
    public function __construct(
        private readonly TeamWorkScheduleService $teamWorkScheduleService,
    ) {}

    public function update(UpdateTeamWorkScheduleRequest $request, User $user): RedirectResponse
    {
        $this->authorize('update', $user);

        $this->teamWorkScheduleService->upsertForUser($user, $request->validated());

        return redirect()
            ->route('users.edit', $user)
            ->with('status', 'work-schedule-updated');
    }
}
