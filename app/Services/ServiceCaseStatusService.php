<?php

namespace App\Services;

use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ServiceCaseStatusService
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly DashboardBroadcastService $dashboardBroadcastService,
    ) {}

    /**
     * @return list<IncidentStatus>
     */
    public static function unfinishedWorkflowStatuses(): array
    {
        return [
            IncidentStatus::Open,
            IncidentStatus::InProgress,
            IncidentStatus::Resolved,
        ];
    }

    public function closeActiveServiceCasesForOrder(Order $order, User $actor): void
    {
        Incident::query()
            ->where('order_id', $order->id)
            ->whereIn('status', self::unfinishedWorkflowStatuses())
            ->orderBy('id')
            ->get()
            ->each(fn (Incident $incident) => $this->updateStatus($incident, IncidentStatus::Closed, $actor));
    }

    public function updateStatus(Incident $incident, IncidentStatus $status, User $actor): Incident
    {
        if ($incident->status === $status) {
            return $incident;
        }

        if ($incident->status === IncidentStatus::Closed) {
            throw ValidationException::withMessages([
                'status' => 'Closed service cases cannot be reopened.',
            ]);
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

            match ($status) {
                IncidentStatus::Resolved => $this->dashboardBroadcastService->serviceCaseResolved($freshIncident, $actor),
                IncidentStatus::Closed => $this->dashboardBroadcastService->serviceCaseClosed($freshIncident, $actor),
                default => null,
            };

            return $freshIncident;
        });
    }
}
