<?php

namespace App\Http\Controllers;

use App\Enums\TeamAvailabilityStatus;
use App\Http\Requests\UpdateTeamAvailabilityRequest;
use App\Services\Operations\TeamAvailabilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;

class TeamAvailabilityController extends Controller
{
    public function __construct(
        private readonly TeamAvailabilityService $availabilityService,
    ) {}

    public function update(UpdateTeamAvailabilityRequest $request): RedirectResponse
    {
        $status = TeamAvailabilityStatus::from($request->validated('availability_status'));

        $leaveStart = filled($request->validated('leave_start_date'))
            ? Carbon::parse($request->validated('leave_start_date'))->startOfDay()
            : null;

        $leaveEnd = filled($request->validated('leave_end_date'))
            ? Carbon::parse($request->validated('leave_end_date'))->startOfDay()
            : null;

        $this->availabilityService->updateStatus(
            user: $request->user(),
            status: $status,
            leaveStartDate: $leaveStart,
            leaveEndDate: $leaveEnd,
        );

        return redirect()
            ->route('profile.edit')
            ->with('status', 'availability-updated');
    }
}
