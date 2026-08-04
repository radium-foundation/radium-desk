<?php

namespace App\Services\Assignment;

use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\AutomationIdentityService;
use App\Services\Operations\OperationsQueueClassifier;
use App\Services\Operations\WorkCalendarService;
use App\Services\ServiceCaseAssignmentService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Ready Queue admin selection only.
 *
 * Skips configured Ready Queue admins on approved leave (including half-day)
 * and picks up unassigned Ready cases when an eligible admin becomes available.
 * Does not alter Support Queue / Smart / Email assignment paths.
 */
class ReadyQueueAdminAssignmentService
{
    public const NO_ELIGIBLE_ADMIN_REASON = 'No eligible Ready Queue admin available.';

    public const NO_ELIGIBLE_ADMIN_EVENT = 'service_case.ready_queue_unassigned';

    public function __construct(
        private readonly ServiceCaseAssignmentService $assignmentService,
        private readonly WorkCalendarService $workCalendarService,
        private readonly AuditLogService $auditLogService,
        private readonly AutomationIdentityService $automationIdentity,
        private readonly OperationsQueueClassifier $queueClassifier,
    ) {}

    /**
     * Configured Ready Queue admin chain with leave gate.
     * Does not change ServiceCaseAssignmentService::resolveAssigneeOrNull (Support fallbacks).
     */
    public function resolveEligibleAdmin(?Carbon $at = null): ?User
    {
        $at ??= now();

        foreach ($this->assignmentService->assigneeCandidateUserIds($at) as $userId) {
            $candidate = $this->findEligibleAdminById((int) $userId, $at);

            if ($candidate !== null) {
                return $candidate;
            }
        }

        return null;
    }

    public function isEligibleReadyQueueAdmin(User $user, ?Carbon $at = null): bool
    {
        return $this->findEligibleAdminById((int) $user->id, $at ?? now()) !== null;
    }

    /**
     * Assign oldest unassigned Ready Queue cases to the first eligible admin.
     * Never modifies cases that already have an assignee.
     */
    public function pickupUnassignedReadyQueueCases(?Carbon $at = null, ?int $limit = null): int
    {
        $at ??= now();
        $admin = $this->resolveEligibleAdmin($at);

        if ($admin === null) {
            return 0;
        }

        $batchSize = max(1, $limit ?? (int) config('service_case_assignment.ready_queue_pickup_batch_size', 25));
        $actor = $this->automationIdentity->systemUser();
        $assigned = 0;

        $candidateIds = Incident::query()
            ->whereNull('assigned_to_user_id')
            ->where('status', '!=', IncidentStatus::Closed)
            ->orderBy('id')
            ->limit($batchSize * 3)
            ->pluck('id');

        foreach ($candidateIds as $incidentId) {
            if ($assigned >= $batchSize) {
                break;
            }

            if ($this->pickupSingleUnassignedReadyCase((int) $incidentId, $actor, $at)) {
                $assigned++;
            }
        }

        if ($assigned > 0) {
            Log::info('ready_queue_admin.pickup_completed', [
                'assigned' => $assigned,
                'admin_user_id' => $admin->id,
            ]);
        }

        return $assigned;
    }

    public function recordNoEligibleAdmin(Incident $incident, User $actor): Incident
    {
        $incident = $incident->fresh(['assignee', 'order']) ?? $incident;

        if ($incident->assigned_to_user_id !== null) {
            return $incident;
        }

        $this->assignmentService->clearAutomationPending($incident, $actor);

        $this->auditLogService->log(
            userId: $actor->id,
            event: self::NO_ELIGIBLE_ADMIN_EVENT,
            auditable: $incident->fresh() ?? $incident,
            oldValues: [
                'assigned_to_user_id' => null,
            ],
            newValues: [
                'assigned_to_user_id' => null,
                'reason' => self::NO_ELIGIBLE_ADMIN_REASON,
                'assignment_override' => true,
                'override_reason' => 'shift_admin',
                'ready_queue_retained_by' => 'ira',
            ],
        );

        return $incident->fresh(['assignee', 'order']) ?? $incident;
    }

    private function pickupSingleUnassignedReadyCase(int $incidentId, User $actor, Carbon $at): bool
    {
        return DB::transaction(function () use ($incidentId, $actor, $at): bool {
            $incident = Incident::query()
                ->whereKey($incidentId)
                ->lockForUpdate()
                ->with(['order', 'assignee', 'supportAppointments'])
                ->first();

            if ($incident === null || $incident->assigned_to_user_id !== null) {
                return false;
            }

            if ($incident->status === IncidentStatus::Closed || ! $incident->isActive()) {
                return false;
            }

            if ($incident->hasActiveSupportAppointment()) {
                return false;
            }

            if ($incident->order?->isInquiryOrder()) {
                return false;
            }

            if (! $this->queueClassifier->isReadyForReferenceEntry($incident)) {
                return false;
            }

            $beforeId = $incident->assigned_to_user_id;
            $result = $this->assignmentService->assignToShiftAdminAfterValidation($incident, $actor, $at);

            return $beforeId === null
                && $result->assigned_to_user_id !== null;
        });
    }

    private function findEligibleAdminById(int $userId, Carbon $at): ?User
    {
        if ($userId <= 0) {
            return null;
        }

        $assignee = User::query()->find($userId);

        if ($assignee === null || $assignee->trashed() || ! $assignee->is_active) {
            return null;
        }

        if (! $assignee->hasRole(\Database\Seeders\RolePermissionSeeder::ROLE_ADMIN)) {
            return null;
        }

        // Approved leave including half-day covering today — no presence checks.
        if ($this->workCalendarService->hasApprovedLeave($assignee, $at)) {
            return null;
        }

        return $assignee;
    }
}
