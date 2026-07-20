<?php

namespace App\Services;

use App\Enums\AssignmentOrigin;
use App\Enums\BusinessHoldType;
use App\Models\BusinessHold;
use App\Models\Incident;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\Dashboard\DashboardSnapshotStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BusinessHoldService
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly ServiceCaseAssignmentService $assignmentService,
        private readonly DashboardSnapshotStore $dashboardSnapshotStore,
        private readonly DashboardBroadcastService $dashboardBroadcastService,
    ) {}

    public function hasActiveHold(Incident $incident, ?BusinessHoldType $type = null): bool
    {
        return $this->activeHold($incident, $type) !== null;
    }

    public function activeHold(Incident $incident, ?BusinessHoldType $type = null): ?BusinessHold
    {
        $incident->loadMissing('activeBusinessHold');

        $hold = $incident->activeBusinessHold;

        if ($hold === null || ! $hold->isActive()) {
            return null;
        }

        if ($type !== null && $hold->hold_type !== $type) {
            return null;
        }

        return $hold;
    }

    public function blocksLifecycleAdvancement(Incident $incident): bool
    {
        return $this->hasActiveHold($incident);
    }

    public function assertOperationsAllowed(Incident $incident, string $operation): void
    {
        if (! $this->hasActiveHold($incident)) {
            return;
        }

        $hold = $this->activeHold($incident);

        throw ValidationException::withMessages([
            $operation => sprintf(
                'This service case is on %s and cannot be %s until the hold is cleared.',
                $hold?->hold_type->label() ?? 'business hold',
                $operation,
            ),
        ]);
    }

    public function activateRefundHold(
        Incident $incident,
        RefundRequest $refund,
        User $actor,
    ): BusinessHold {
        if ($this->hasActiveHold($incident)) {
            throw ValidationException::withMessages([
                'refund' => 'This service case already has an active business hold.',
            ]);
        }

        return DB::transaction(function () use ($incident, $refund, $actor): BusinessHold {
            $hold = BusinessHold::query()->create([
                'incident_id' => $incident->id,
                'hold_type' => BusinessHoldType::Refund,
                'source_type' => $refund->getMorphClass(),
                'source_id' => $refund->id,
                'activated_at' => now(),
                'activated_by' => $actor->id,
                'metadata' => [
                    'refund_reference_no' => $refund->reference_no,
                    'requested_by_user_id' => $refund->requested_by,
                ],
            ]);

            $incident->unsetRelation('activeBusinessHold');

            $this->auditLogService->log(
                userId: $actor->id,
                event: 'business_hold.activated',
                auditable: $incident,
                newValues: [
                    'hold_type' => BusinessHoldType::Refund->value,
                    'hold_type_label' => BusinessHoldType::Refund->label(),
                    'business_hold_id' => $hold->id,
                    'refund_request_id' => $refund->id,
                    'refund_reference_no' => $refund->reference_no,
                    'requested_by_user_id' => $refund->requested_by,
                ],
            );

            $this->dashboardSnapshotStore->forget();

            $this->dashboardBroadcastService->serviceCaseQueueMembershipChanged(
                $incident->fresh([
                    'order.transactionAssigner',
                    'creator',
                    'assignee.roles',
                    'activeWaitingState',
                    'activeBusinessHold',
                    'supportAppointments',
                ]),
                $actor,
            );

            return $hold;
        });
    }

    public function clearActiveHold(
        Incident $incident,
        User $actor,
        string $source,
        ?BusinessHoldType $type = null,
    ): ?BusinessHold {
        $hold = $this->activeHold($incident, $type);

        if ($hold === null) {
            return null;
        }

        return DB::transaction(function () use ($hold, $incident, $actor, $source): BusinessHold {
            $hold->update([
                'cleared_at' => now(),
                'cleared_by' => $actor->id,
            ]);

            $incident->unsetRelation('activeBusinessHold');

            $this->auditLogService->log(
                userId: $actor->id,
                event: 'business_hold.cleared',
                auditable: $incident,
                oldValues: [
                    'hold_type' => $hold->hold_type->value,
                    'business_hold_id' => $hold->id,
                ],
                newValues: [
                    'hold_type' => $hold->hold_type->value,
                    'hold_type_label' => $hold->hold_type->label(),
                    'business_hold_id' => $hold->id,
                    'cleared_at' => $hold->cleared_at?->toIso8601String(),
                    'resolution_source' => $source,
                ],
            );

            $this->dashboardSnapshotStore->forget();

            $this->dashboardBroadcastService->serviceCaseQueueMembershipChanged(
                $incident->fresh([
                    'order.transactionAssigner',
                    'creator',
                    'assignee.roles',
                    'activeWaitingState',
                    'activeBusinessHold',
                    'supportAppointments',
                ]),
                $actor,
            );

            return $hold->fresh();
        });
    }

    public function restoreToRequestingAgentAfterRefundRejected(
        RefundRequest $refund,
        User $actor,
    ): void {
        $refund->loadMissing('incident.assignee', 'requester');

        $incident = $refund->incident;

        if ($incident === null || ! $incident->isActive()) {
            return;
        }

        $requester = $refund->requester;

        if ($requester === null || ! $requester->is_active || $requester->trashed()) {
            return;
        }

        if ($incident->assigned_to_user_id === $requester->id) {
            return;
        }

        $this->assignmentService->assignWithAuditContext(
            incident: $incident,
            assignee: $requester,
            actor: $actor,
            auditContext: [
                'assignment_override' => true,
                'override_reason' => 'refund_rejected',
                'refund_request_id' => $refund->id,
                'refund_reference_no' => $refund->reference_no,
            ],
            event: 'service_case.reassigned',
            assignmentOrigin: AssignmentOrigin::Manual,
        );
    }
}
