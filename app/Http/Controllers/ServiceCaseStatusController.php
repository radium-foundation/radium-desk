<?php

namespace App\Http\Controllers;

use App\Enums\IncidentStatus;
use App\Http\Requests\UpdateServiceCaseStatusRequest;
use App\Models\Incident;
use App\Services\ServiceCaseStatusService;
use Illuminate\Http\RedirectResponse;

class ServiceCaseStatusController extends Controller
{
    public function __construct(
        private readonly ServiceCaseStatusService $serviceCaseStatusService,
    ) {}

    public function update(UpdateServiceCaseStatusRequest $request, Incident $incident): RedirectResponse
    {
        $status = $request->enum('status', IncidentStatus::class);

        $this->serviceCaseStatusService->updateStatus(
            incident: $incident,
            status: $status,
            actor: $request->user(),
        );

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
