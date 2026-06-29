<?php

namespace App\Services;

use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ServiceCaseActionRemarkService
{
    public function __construct(
        private readonly RemarkService $remarkService,
        private readonly ServiceCaseStatusService $statusService,
    ) {}

    public function execute(
        Incident $incident,
        User $actor,
        IncidentStatus $status,
        string $body,
        ?Request $request = null,
    ): Incident {
        return DB::transaction(function () use ($incident, $actor, $status, $body, $request): Incident {
            $this->remarkService->createForRemarkable(
                remarkable: $incident,
                actor: $actor,
                body: $body,
                request: $request,
            );

            return $this->statusService->updateStatus(
                incident: $incident,
                status: $status,
                actor: $actor,
            );
        });
    }
}
