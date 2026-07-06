<?php

namespace App\Http\Controllers;

use App\Enums\TeamAvailabilityStatus;
use App\Http\Requests\UpdateTeamAvailabilityRequest;
use App\Services\Operations\TeamAvailabilityService;
use Illuminate\Http\RedirectResponse;

class TeamAvailabilityController extends Controller
{
    public function __construct(
        private readonly TeamAvailabilityService $availabilityService,
    ) {}

    public function update(UpdateTeamAvailabilityRequest $request): RedirectResponse
    {
        $status = TeamAvailabilityStatus::from($request->validated('availability_status'));

        $this->availabilityService->updateStatus(
            user: $request->user(),
            status: $status,
        );

        return redirect()
            ->route('profile.edit')
            ->with('status', 'availability-updated');
    }
}
