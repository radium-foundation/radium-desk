<?php

namespace App\Services;

use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ServiceCaseStatusService
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    public function updateStatus(Incident $incident, IncidentStatus $status, User $actor): Incident
    {
        if ($incident->status === $status) {
            return $incident;
        }

        return DB::transaction(function () use ($incident, $status, $actor): Incident {
            $oldStatus = $incident->status;

            $incident->update([
                'status' => $status,
                'updated_by' => $actor->id,
            ]);

            $freshIncident = $incident->fresh();

            $this->auditLogService->log(
                userId: $actor->id,
                event: 'service_case.status_changed',
                auditable: $freshIncident,
                oldValues: ['status' => $oldStatus->value],
                newValues: ['status' => $status->value],
            );

            return $freshIncident;
        });
    }
}
