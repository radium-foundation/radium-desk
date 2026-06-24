<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReassignServiceCaseRequest;
use App\Models\Incident;
use App\Models\User;
use App\Services\ServiceCaseAssignmentService;
use Illuminate\Http\RedirectResponse;

class ServiceCaseAssignmentController extends Controller
{
    public function __construct(
        private readonly ServiceCaseAssignmentService $serviceCaseAssignmentService,
    ) {}

    public function update(ReassignServiceCaseRequest $request, Incident $incident): RedirectResponse
    {
        $assignee = User::query()->findOrFail($request->integer('assigned_to_user_id'));

        $this->serviceCaseAssignmentService->reassign(
            incident: $incident,
            assignee: $assignee,
            actor: $request->user(),
        );

        return redirect()
            ->route('incidents.show', $incident)
            ->with('status', 'service-case-reassigned');
    }
}
