<?php

namespace App\Services;

use App\Enums\IncidentStatus;
use App\Enums\SupportAppointmentStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\SupportAppointment;
use App\Models\User;
use App\Services\Operations\TeamMemberActivityService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ServiceCaseStatusService
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly DashboardBroadcastService $dashboardBroadcastService,
        private readonly IncidentWaitingStateService $waitingStateService,
    ) {}

    /**
     * @return list<IncidentStatus>
     */
    public static function unfinishedWorkflowStatuses(): array
    {
        return [
            IncidentStatus::Open,
            IncidentStatus::InProgress,
            IncidentStatus::AwaitingProductDetails,
        ];
    }

    public function closeActiveServiceCasesForOrder(Order $order, User $actor, bool $broadcast = true): void
    {
        Incident::query()
            ->where('order_id', $order->id)
            ->where('status', '!=', IncidentStatus::Closed)
            ->orderBy('id')
            ->get()
            ->each(fn (Incident $incident) => $this->updateStatus(
                incident: $incident,
                status: IncidentStatus::Closed,
                actor: $actor,
                broadcast: $broadcast,
            ));
    }

    public function updateStatus(
        Incident $incident,
        IncidentStatus $status,
        User $actor,
        bool $broadcast = true,
    ): Incident {
        if ($incident->status === $status) {
            return $incident;
        }

        if ($incident->status === IncidentStatus::Closed) {
            throw ValidationException::withMessages([
                'status' => 'Closed service cases cannot be updated. Use reopen instead.',
            ]);
        }

        if ($status === IncidentStatus::Closed) {
            app(BusinessHoldService::class)->assertOperationsAllowed($incident, 'closed');
            $this->validateAgentResolutionRequirements($incident, $actor);
        }

        return DB::transaction(function () use ($incident, $status, $actor, $broadcast): Incident {
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

            app(TeamMemberActivityService::class)
                ->recordStatusChange($actor);

            if ($status === IncidentStatus::Closed) {
                $this->waitingStateService->clearActiveIfPresent(
                    incident: $freshIncident,
                    actor: $actor,
                    broadcast: $broadcast,
                );
                $this->completeScheduledSupportAppointments($freshIncident);

                if ($broadcast) {
                    $this->dashboardBroadcastService->serviceCaseClosed($freshIncident, $actor);
                }
            } elseif ($status === IncidentStatus::Resolved) {
                if ($broadcast) {
                    $this->dashboardBroadcastService->serviceCaseResolved($freshIncident, $actor);
                }
            } elseif ($broadcast) {
                $this->dashboardBroadcastService->serviceCaseQueueMembershipChanged($freshIncident, $actor);
            }

            return $freshIncident;
        });
    }

    public function reopen(Incident $incident, User $actor): Incident
    {
        if ($incident->status !== IncidentStatus::Closed) {
            throw ValidationException::withMessages([
                'status' => 'Only closed service cases can be reopened.',
            ]);
        }

        return DB::transaction(function () use ($incident, $actor): Incident {
            $oldStatus = $incident->status;

            $incident->update([
                'status' => IncidentStatus::Open,
                'updated_by' => $actor->id,
            ]);

            $freshIncident = $incident->fresh();

            $this->auditLogService->log(
                userId: $actor->id,
                event: 'service_case.status_changed',
                auditable: $freshIncident,
                oldValues: ['status' => $oldStatus->value],
                newValues: ['status' => IncidentStatus::Open->value],
            );

            app(TeamMemberActivityService::class)
                ->recordStatusChange($actor);

            $this->dashboardBroadcastService->serviceCaseQueueMembershipChanged($freshIncident, $actor);

            return $freshIncident;
        });
    }

    /**
     * Mark all scheduled appointments for the case as completed.
     *
     * Used on manual close and by the closed-appointment repair cleanup path.
     */
    public function completeScheduledSupportAppointments(Incident $incident): int
    {
        return SupportAppointment::query()
            ->where('incident_id', $incident->id)
            ->where('status', SupportAppointmentStatus::Scheduled)
            ->update(['status' => SupportAppointmentStatus::Completed]);
    }

    private function validateAgentResolutionRequirements(Incident $incident, User $actor): void
    {
        if (! $actor->hasRole(RolePermissionSeeder::ROLE_AGENT)
            || $actor->hasAnyRole([
                RolePermissionSeeder::ROLE_ADMIN,
                RolePermissionSeeder::ROLE_OPERATIONS_ADMIN,
                RolePermissionSeeder::ROLE_SUPERADMIN,
            ])) {
            return;
        }

        $messages = [];

        if ($incident->remarks()->count() === 0) {
            $messages['remarks'] = 'Add at least one remark before closing this service case.';
        }

        $order = $incident->order;

        if ($order !== null
            && ! $order->isRemoteSupportOrder()
            && ! $order->isInquiryOrder()
            && ($order->transaction_id === null || trim((string) $order->transaction_id) === '')) {
            $messages['transaction_id'] = 'Assign a transaction ID to the related order before closing this service case.';
        }

        if ($messages !== []) {
            throw ValidationException::withMessages($messages);
        }
    }
}
