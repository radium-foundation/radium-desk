<?php

namespace App\Http\Controllers;

use App\Enums\IncidentStatus;
use App\Http\Requests\UpdateServiceCaseStatusRequest;
use App\Models\Incident;
use App\Services\ServiceCaseActionRemarkService;
use App\Services\ServiceCaseStatusService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class ServiceCaseStatusController extends Controller
{
    public function __construct(
        private readonly ServiceCaseStatusService $serviceCaseStatusService,
        private readonly ServiceCaseActionRemarkService $actionRemarkService,
    ) {}

    public function update(UpdateServiceCaseStatusRequest $request, Incident $incident): RedirectResponse
    {
        $status = $request->enum('status', IncidentStatus::class);
        $actor = $request->user();

        try {
            if (in_array($status, [IncidentStatus::Resolved, IncidentStatus::Closed], true)) {
                $incident = $this->actionRemarkService->execute(
                    incident: $incident,
                    actor: $actor,
                    status: $status,
                    body: $request->string('body')->toString(),
                    request: $request,
                );
            } else {
                $incident = $this->serviceCaseStatusService->updateStatus(
                    incident: $incident,
                    status: $status,
                    actor: $actor,
                );
            }
        } catch (ValidationException $exception) {
            return redirect()
                ->route('incidents.show', $incident)
                ->withFragment('activity-timeline')
                ->withInput()
                ->withErrors($exception->errors());
        }

        $flashKey = match ($status) {
            IncidentStatus::Resolved => 'service-case-resolved',
            IncidentStatus::Closed => 'service-case-closed',
            default => 'service-case-status-updated',
        };

        return redirect()
            ->route('incidents.show', $incident)
            ->with('status', $flashKey)
            ->withFragment('activity-timeline');
    }
}
