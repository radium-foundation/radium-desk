<?php

namespace App\Services\IncomingEmail;

use App\Enums\AssignmentOrigin;
use App\Enums\IncidentStatus;
use App\Enums\IncomingEmailClassification;
use App\Enums\IntakeChannel;
use App\Enums\ServiceCaseCloseReasonForClosing;
use App\Models\Incident;
use App\Models\IncomingEmailMessage;
use App\Models\ServiceCaseCloseOutcome;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\RemarkService;
use App\Services\ServiceCaseAssignmentService;
use App\Services\ServiceCasePriorityService;
use App\Services\ServiceCaseStatusService;
use App\Support\Remarks\RemarkSystemSource;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 1.1 — reopen a legitimate closed Service Case for inbound email.
 *
 * Never creates a duplicate case. Reuses existing lifecycle, link, priority,
 * assignment, and notification services.
 */
class IncomingEmailClosedCaseReopenService
{
    public function __construct(
        private readonly ServiceCaseStatusService $statusService,
        private readonly ServiceCaseAssignmentService $assignmentService,
        private readonly ServiceCasePriorityService $priorityService,
        private readonly IncomingEmailLinkService $linkService,
        private readonly IncomingEmailAssignmentService $emailAssignmentService,
        private readonly RemarkService $remarkService,
        private readonly AuditLogService $auditLogService,
    ) {}

    public function isReopenable(Incident $incident): bool
    {
        if ($incident->trashed()) {
            return false;
        }

        if ($incident->status !== IncidentStatus::Closed) {
            return false;
        }

        if ($incident->order_id === null) {
            return false;
        }

        $reason = $this->latestCloseReason($incident);

        if ($reason === ServiceCaseCloseReasonForClosing::CustomerCancelled) {
            return false;
        }

        if ($reason === ServiceCaseCloseReasonForClosing::DuplicateCase) {
            return false;
        }

        return true;
    }

    public function reopenLinkAndRoute(
        Incident $closedIncident,
        IncomingEmailMessage $message,
        User $actor,
        IncomingEmailClassification $classification,
    ): IncomingEmailMessage {
        if (! $this->isReopenable($closedIncident)) {
            throw new RuntimeException(
                'Service case is not eligible for inbound-email reopen.',
            );
        }

        return DB::transaction(function () use ($closedIncident, $message, $actor, $classification): IncomingEmailMessage {
            $locked = Incident::query()
                ->whereKey($closedIncident->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $this->isReopenable($locked)) {
                throw new RuntimeException(
                    'Service case is not eligible for inbound-email reopen.',
                );
            }

            $previousStatus = $locked->status;
            $previousOwnerId = $locked->assigned_to_user_id;
            $stickyOwnerId = $this->stickyOwnerUserId($locked);
            $preferredOwnerId = $previousOwnerId ?? $stickyOwnerId;

            $this->remarkService->createSystemRemarkForRemarkable(
                remarkable: $locked,
                actor: $actor,
                body: sprintf(
                    'Service case reopened by inbound email%s.',
                    filled($message->rfc_message_id)
                        ? ' '.$message->rfc_message_id
                        : (filled($message->provider_message_id) ? ' '.$message->provider_message_id : ''),
                ),
                systemSource: RemarkSystemSource::REOPEN,
            );

            $reopened = $this->statusService->reopen($locked, $actor);

            $restoredOwner = $this->resolveRestorableOwner($preferredOwnerId);

            if ($restoredOwner instanceof User
                && (int) $reopened->assigned_to_user_id !== (int) $restoredOwner->id) {
                $reopened = $this->assignmentService->assignWithAuditContext(
                    incident: $reopened,
                    assignee: $restoredOwner,
                    actor: $actor,
                    auditContext: [
                        'assignment_method' => 'inbound_email_reopen_previous_owner',
                        'assignment_reason' => 'restore_previous_owner',
                        'intake_channel' => 'email',
                        'previous_owner_user_id' => $previousOwnerId,
                        'sticky_owner_user_id' => $stickyOwnerId,
                        'incoming_email_message_id' => $message->id,
                    ],
                    event: 'service_case.assigned',
                    assignmentOrigin: AssignmentOrigin::Auto,
                );
            }

            $this->auditLogService->log(
                userId: $actor->id,
                event: 'incoming_email.case_reopened',
                auditable: $reopened->fresh(),
                oldValues: [
                    'status' => $previousStatus->value,
                    'assigned_to_user_id' => $previousOwnerId,
                ],
                newValues: [
                    'status' => IncidentStatus::Open->value,
                    'assigned_to_user_id' => $reopened->fresh()->assigned_to_user_id,
                    'previous_status' => $previousStatus->value,
                    'previous_owner_user_id' => $previousOwnerId,
                    'incoming_email_message_id' => $message->id,
                    'rfc_message_id' => $message->rfc_message_id,
                    'thread_id' => $message->thread_id,
                    'provider_message_id' => $message->provider_message_id,
                    'reason' => 'inbound_email',
                    'reopened_at' => now()->toIso8601String(),
                ],
            );

            $linkedMessage = $this->linkService->link(
                $reopened->fresh(),
                $message->fresh(),
                $actor,
                $classification,
            );

            $boosted = $this->priorityService->applyInboundLinkBoost(
                $reopened->fresh(['order', 'assignee']),
                IntakeChannel::Email,
                $actor,
            );

            $this->emailAssignmentService->routeLinkedEmail(
                $boosted->fresh(['assignee', 'order']),
                $linkedMessage->fresh(),
                $actor,
            );

            return $linkedMessage->fresh();
        });
    }

    private function latestCloseReason(Incident $incident): ?ServiceCaseCloseReasonForClosing
    {
        $outcome = ServiceCaseCloseOutcome::query()
            ->where('incident_id', $incident->id)
            ->orderByDesc('id')
            ->first();

        return $outcome?->reason_for_closing;
    }

    private function stickyOwnerUserId(Incident $incident): ?int
    {
        $outcome = ServiceCaseCloseOutcome::query()
            ->where('incident_id', $incident->id)
            ->orderByDesc('id')
            ->first();

        $sticky = $outcome?->metadata['sticky_agent_user_id'] ?? null;

        return is_numeric($sticky) ? (int) $sticky : null;
    }

    private function resolveRestorableOwner(?int $userId): ?User
    {
        if ($userId === null || $userId <= 0) {
            return null;
        }

        $user = User::query()->find($userId);

        if (! $user instanceof User || ! $user->is_active || $user->trashed()) {
            return null;
        }

        return $user;
    }
}
