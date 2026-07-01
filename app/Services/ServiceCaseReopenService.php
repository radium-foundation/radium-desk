<?php

namespace App\Services;

use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ServiceCaseReopenService
{
    public function __construct(
        private readonly RemarkService $remarkService,
        private readonly ServiceCaseAssignmentService $assignmentService,
        private readonly ServiceCaseStatusService $statusService,
    ) {}

    public function reopen(
        Incident $incident,
        User $actor,
        string $body,
        string $reason,
        ?User $assignee = null,
        ?Request $request = null,
    ): Incident {
        if ($incident->status !== IncidentStatus::Closed) {
            throw ValidationException::withMessages([
                'action_type' => 'Only closed service cases can be reopened.',
            ]);
        }

        return DB::transaction(function () use ($incident, $actor, $body, $reason, $assignee, $request): Incident {
            $this->remarkService->createForRemarkable(
                remarkable: $incident,
                actor: $actor,
                body: $body,
                request: $request,
            );

            $freshIncident = $this->statusService->reopen($incident, $actor);

            if ($assignee !== null) {
                $freshIncident = $this->assignmentService->reassign(
                    incident: $freshIncident,
                    assignee: $assignee,
                    actor: $actor,
                );
            }

            return $freshIncident->fresh(['assignee', 'order', 'creator', 'updater']);
        });
    }
}
