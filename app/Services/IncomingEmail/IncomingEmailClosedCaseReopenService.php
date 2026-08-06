<?php

namespace App\Services\IncomingEmail;

use App\Enums\AssignmentOrigin;
use App\Enums\IncidentStatus;
use App\Enums\IncomingEmailClassification;
use App\Enums\IntakeChannel;
use App\Enums\RefundStatus;
use App\Enums\ServiceCaseCloseReasonForClosing;
use App\Enums\ServiceCaseReopenAssignmentReason;
use App\Enums\ServiceCaseReopenSource;
use App\Models\Incident;
use App\Models\IncomingEmailMessage;
use App\Models\RefundRequest;
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
 * Never creates a duplicate case. Routing depends on why the case was closed:
 * refund-completed → Refund Desk; successful service → last owner; else default.
 */
class IncomingEmailClosedCaseReopenService
{
    public const ASSIGNMENT_METHOD_LAST_OWNER = 'inbound_email_reopen_previous_owner';

    public const ASSIGNMENT_METHOD_REFUND_DESK = 'inbound_email_reopen_refund_desk';

    /**
     * Close reasons treated as successful service completion for reopen routing.
     *
     * @var list<ServiceCaseCloseReasonForClosing>
     */
    private const SUCCESSFUL_SERVICE_CLOSE_REASONS = [
        ServiceCaseCloseReasonForClosing::IssueResolved,
        ServiceCaseCloseReasonForClosing::ReplacementIssued,
        ServiceCaseCloseReasonForClosing::PaymentCollectedOffline,
        ServiceCaseCloseReasonForClosing::ApprovedByAdmin,
    ];

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
                ->with(['order', 'assignee'])
                ->firstOrFail();

            if (! $this->isReopenable($locked)) {
                throw new RuntimeException(
                    'Service case is not eligible for inbound-email reopen.',
                );
            }

            $previousStatus = $locked->status;
            $previousOwnerId = $locked->assigned_to_user_id;
            $stickyOwnerId = $this->stickyOwnerUserId($locked);
            $assignmentReason = $this->resolveAssignmentReason($locked);
            $reopenSource = ServiceCaseReopenSource::CustomerEmail;

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

            $reopened = $this->applyReopenAssignment(
                incident: $reopened,
                actor: $actor,
                message: $message,
                assignmentReason: $assignmentReason,
                previousOwnerId: $previousOwnerId,
                stickyOwnerId: $stickyOwnerId,
            );

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
                    'reopened_by' => $reopenSource->value,
                    'reopened_by_label' => $reopenSource->label(),
                    'assigned_because' => $assignmentReason->value,
                    'assigned_because_label' => $assignmentReason->label(),
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

    private function resolveAssignmentReason(Incident $incident): ServiceCaseReopenAssignmentReason
    {
        if ($this->isSuccessfulServiceClose($incident)) {
            return ServiceCaseReopenAssignmentReason::LastOwner;
        }

        if ($this->hasTerminalCompletedRefund($incident)) {
            return ServiceCaseReopenAssignmentReason::RefundWorkflow;
        }

        return ServiceCaseReopenAssignmentReason::DefaultRouting;
    }

    private function isSuccessfulServiceClose(Incident $incident): bool
    {
        $reason = $this->latestCloseReason($incident);

        if ($reason !== null && in_array($reason, self::SUCCESSFUL_SERVICE_CLOSE_REASONS, true)) {
            return true;
        }

        // Reference number issued implies successful service completion even when
        // legacy closes have no ServiceCaseCloseOutcome row.
        $incident->loadMissing('order');
        $order = $incident->order;

        if ($order === null || ! filled(trim((string) $order->transaction_id))) {
            return false;
        }

        // Prefer refund routing when a terminal refund exists on the same case.
        return ! $this->hasTerminalCompletedRefund($incident);
    }

    private function hasTerminalCompletedRefund(Incident $incident): bool
    {
        return RefundRequest::query()
            ->where('incident_id', $incident->id)
            ->whereIn('status', [
                RefundStatus::Completed->value,
                RefundStatus::Closed->value,
                RefundStatus::Approved->value,
            ])
            ->exists();
    }

    private function applyReopenAssignment(
        Incident $incident,
        User $actor,
        IncomingEmailMessage $message,
        ServiceCaseReopenAssignmentReason $assignmentReason,
        ?int $previousOwnerId,
        ?int $stickyOwnerId,
    ): Incident {
        if ($assignmentReason === ServiceCaseReopenAssignmentReason::RefundWorkflow) {
            return $this->assignToRefundDesk(
                incident: $incident,
                actor: $actor,
                message: $message,
                previousOwnerId: $previousOwnerId,
            );
        }

        $preferredOwnerId = $previousOwnerId ?? $stickyOwnerId;
        $restoredOwner = $this->resolveRestorableOwner($preferredOwnerId);

        if (! $restoredOwner instanceof User) {
            return $incident;
        }

        if ((int) $incident->assigned_to_user_id === (int) $restoredOwner->id) {
            return $incident;
        }

        return $this->assignmentService->assignWithAuditContext(
            incident: $incident,
            assignee: $restoredOwner,
            actor: $actor,
            auditContext: [
                'assignment_method' => self::ASSIGNMENT_METHOD_LAST_OWNER,
                'assignment_reason' => 'restore_previous_owner',
                'assigned_because' => $assignmentReason->value,
                'assigned_because_label' => $assignmentReason->label(),
                'reopened_by' => ServiceCaseReopenSource::CustomerEmail->value,
                'reopened_by_label' => ServiceCaseReopenSource::CustomerEmail->label(),
                'intake_channel' => 'email',
                'previous_owner_user_id' => $previousOwnerId,
                'sticky_owner_user_id' => $stickyOwnerId,
                'incoming_email_message_id' => $message->id,
            ],
            event: 'service_case.assigned',
            assignmentOrigin: AssignmentOrigin::Auto,
        );
    }

    private function assignToRefundDesk(
        Incident $incident,
        User $actor,
        IncomingEmailMessage $message,
        ?int $previousOwnerId,
    ): Incident {
        $refundDesk = $this->resolveRefundDeskUser();

        if (! $refundDesk instanceof User) {
            // Configured Refund Desk owner missing — keep current assignee; intake
            // routing may still fill an unassigned case.
            return $incident;
        }

        if ((int) $incident->assigned_to_user_id === (int) $refundDesk->id) {
            return $incident;
        }

        return $this->assignmentService->assignWithAuditContext(
            incident: $incident,
            assignee: $refundDesk,
            actor: $actor,
            auditContext: [
                'assignment_method' => self::ASSIGNMENT_METHOD_REFUND_DESK,
                'assignment_reason' => 'refund_workflow',
                'assigned_because' => ServiceCaseReopenAssignmentReason::RefundWorkflow->value,
                'assigned_because_label' => ServiceCaseReopenAssignmentReason::RefundWorkflow->label(),
                'reopened_by' => ServiceCaseReopenSource::CustomerEmail->value,
                'reopened_by_label' => ServiceCaseReopenSource::CustomerEmail->label(),
                'intake_channel' => 'email',
                'previous_owner_user_id' => $previousOwnerId,
                'incoming_email_message_id' => $message->id,
            ],
            event: 'service_case.assigned',
            assignmentOrigin: AssignmentOrigin::Auto,
        );
    }

    private function resolveRefundDeskUser(): ?User
    {
        $email = strtolower(trim((string) config('inbound_email.reopen.refund_desk_user_email', '')));

        if ($email === '') {
            $email = strtolower(trim((string) config(
                'service_case_assignment.escalation.level_1_email',
                'shubhanshi@radiumbox.com',
            )));
        }

        if ($email === '') {
            return null;
        }

        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();

        if (! $user instanceof User || ! $user->is_active || $user->trashed()) {
            return null;
        }

        return $user;
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
