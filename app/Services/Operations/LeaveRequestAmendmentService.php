<?php

namespace App\Services\Operations;

use App\Contracts\Workforce\WorkforceEventPublisher;
use App\Data\Workforce\WorkforceEvent;
use App\Enums\LeaveAmendmentSource;
use App\Enums\LeaveAmendmentStatus;
use App\Enums\LeaveAmendmentType;
use App\Enums\LeaveDuration;
use App\Enums\LeaveRequestStatus;
use App\Enums\NotificationCategory;
use App\Enums\NotificationChannelType;
use App\Enums\WorkforceAuditEvent;
use App\Enums\WorkforceEventType;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestAmendment;
use App\Models\User;
use App\Notifications\LeaveAmendmentDecisionNotification;
use App\Notifications\LeaveAmendmentSubmittedNotification;
use App\Services\AuditLogService;
use App\Services\Notifications\NotificationAuthorityService;
use App\Services\Workforce\PayrollMonthLockService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeaveRequestAmendmentService
{
    public function __construct(
        private readonly LeaveRequestService $leaveRequestService,
        private readonly NotificationAuthorityService $notificationAuthority,
        private readonly AuditLogService $auditLogService,
        private readonly AttendanceRegisterService $attendanceRegisterService,
        private readonly WorkforceEventPublisher $workforceEventPublisher,
        private readonly PayrollMonthLockService $payrollMonthLockService,
    ) {}

    public function canManage(User $user): bool
    {
        return $user->can('leave-requests.manage');
    }

    public function canReviewAmendment(User $reviewer, LeaveRequestAmendment $amendment): bool
    {
        if (! $this->canManage($reviewer)) {
            return false;
        }

        if ($amendment->source === LeaveAmendmentSource::AgentRequest
            && (int) $amendment->requested_by === (int) $reviewer->id) {
            return false;
        }

        return $amendment->isPending();
    }

    /**
     * @param  array{type: string, reason: string, proposed_start_date?: string, proposed_end_date?: string, proposed_duration?: string}  $data
     */
    public function submitAgentAmendment(User $requester, LeaveRequest $leaveRequest, array $data): LeaveRequestAmendment
    {
        $type = LeaveAmendmentType::tryFrom((string) $data['type']);

        if ($type === null) {
            throw ValidationException::withMessages([
                'type' => 'Invalid amendment type.',
            ]);
        }

        $amendment = DB::transaction(function () use ($requester, $leaveRequest, $data, $type): LeaveRequestAmendment {
            $lockedLeave = $this->lockLeaveRequest($leaveRequest);

            $this->assertAmendableByAgent($requester, $lockedLeave);
            $this->assertNoPendingAmendment($lockedLeave);

            [$proposedStart, $proposedEnd, $proposedDuration] = $this->resolveProposedDates(
                type: $type,
                leaveRequest: $lockedLeave,
                data: $data,
            );

            $this->assertPayrollWritableForAmendment(
                previousStart: $lockedLeave->start_date->copy()->startOfDay(),
                previousEnd: $lockedLeave->end_date->copy()->startOfDay(),
                proposedStart: $proposedStart,
                proposedEnd: $proposedEnd,
            );

            if ($type === LeaveAmendmentType::DateChange) {
                $this->leaveRequestService->assertNoOverlappingLeaveForAmendment(
                    user: $lockedLeave->user,
                    startDate: $proposedStart,
                    endDate: $proposedEnd,
                    excludeLeaveRequestId: $lockedLeave->id,
                );
            }

            $amendment = LeaveRequestAmendment::query()->create([
                'leave_request_id' => $lockedLeave->id,
                'type' => $type,
                'source' => LeaveAmendmentSource::AgentRequest,
                'requested_by' => $requester->id,
                'previous_start_date' => $lockedLeave->start_date->toDateString(),
                'previous_end_date' => $lockedLeave->end_date->toDateString(),
                'previous_duration' => $lockedLeave->duration->value,
                'proposed_start_date' => $proposedStart?->toDateString(),
                'proposed_end_date' => $proposedEnd?->toDateString(),
                'proposed_duration' => $proposedDuration?->value,
                'reason' => $data['reason'],
                'status' => LeaveAmendmentStatus::Pending,
            ]);

            $this->auditAmendmentEvent(
                event: WorkforceAuditEvent::LeaveAmendmentSubmitted,
                userId: $requester->id,
                amendment: $amendment->fresh(['leaveRequest', 'requester']),
            );

            return $amendment;
        });

        $this->notifyManagersOfSubmission($amendment->fresh(['leaveRequest.user']));

        return $amendment;
    }

    public function approve(LeaveRequestAmendment $amendment, User $reviewer, ?string $reviewNotes = null): LeaveRequestAmendment
    {
        $this->assertReviewNotesProvided($reviewNotes);

        $amendment = DB::transaction(function () use ($amendment, $reviewer, $reviewNotes): LeaveRequestAmendment {
            $lockedAmendment = $this->lockAmendment($amendment);
            $this->assertCanReviewAmendment($reviewer, $lockedAmendment);

            $leaveRequest = $this->lockLeaveRequest($lockedAmendment->leaveRequest);

            if ($leaveRequest->status !== LeaveRequestStatus::Approved) {
                throw ValidationException::withMessages([
                    'status' => 'Only approved leave requests can have amendments applied.',
                ]);
            }

            $previousStart = $leaveRequest->start_date->copy()->startOfDay();
            $previousEnd = $leaveRequest->end_date->copy()->startOfDay();

            $this->assertPayrollWritableForAmendment(
                previousStart: $previousStart,
                previousEnd: $previousEnd,
                proposedStart: $lockedAmendment->proposed_start_date?->copy()->startOfDay(),
                proposedEnd: $lockedAmendment->proposed_end_date?->copy()->startOfDay(),
            );

            if ($lockedAmendment->type === LeaveAmendmentType::DateChange) {
                $this->leaveRequestService->assertNoOverlappingLeaveForAmendment(
                    user: $leaveRequest->user,
                    startDate: $lockedAmendment->proposed_start_date->copy()->startOfDay(),
                    endDate: $lockedAmendment->proposed_end_date->copy()->startOfDay(),
                    excludeLeaveRequestId: $leaveRequest->id,
                );
            }

            $lockedAmendment->fill([
                'status' => LeaveAmendmentStatus::Approved,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_notes' => $reviewNotes,
            ])->save();

            $this->applyApprovedAmendment($leaveRequest, $lockedAmendment, $reviewer);

            $lockedAmendment = $lockedAmendment->fresh(['leaveRequest', 'requester', 'reviewer']);

            $this->auditAmendmentEvent(
                event: WorkforceAuditEvent::LeaveAmendmentApproved,
                userId: $reviewer->id,
                amendment: $lockedAmendment,
            );

            return $lockedAmendment;
        });

        $this->notifyRequesterOfDecision($amendment);

        return $amendment;
    }

    public function reject(LeaveRequestAmendment $amendment, User $reviewer, ?string $reviewNotes = null): LeaveRequestAmendment
    {
        $this->assertReviewNotesProvided($reviewNotes);

        $amendment = DB::transaction(function () use ($amendment, $reviewer, $reviewNotes): LeaveRequestAmendment {
            $lockedAmendment = $this->lockAmendment($amendment);
            $this->assertCanReviewAmendment($reviewer, $lockedAmendment);

            $lockedAmendment->fill([
                'status' => LeaveAmendmentStatus::Rejected,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_notes' => $reviewNotes,
            ])->save();

            $lockedAmendment = $lockedAmendment->fresh(['leaveRequest', 'requester', 'reviewer']);

            $this->auditAmendmentEvent(
                event: WorkforceAuditEvent::LeaveAmendmentRejected,
                userId: $reviewer->id,
                amendment: $lockedAmendment,
            );

            return $lockedAmendment;
        });

        $this->notifyRequesterOfDecision($amendment);

        return $amendment;
    }

    /**
     * @param  array{reason: string, proposed_start_date?: string, proposed_end_date?: string, proposed_duration?: string, review_notes: string}  $data
     */
    public function manageDateChange(User $manager, LeaveRequest $leaveRequest, array $data): LeaveRequestAmendment
    {
        $this->assertCanManage($manager);

        return DB::transaction(function () use ($manager, $leaveRequest, $data): LeaveRequestAmendment {
            $lockedLeave = $this->lockLeaveRequest($leaveRequest);
            $this->assertManageableApprovedLeave($lockedLeave);

            $proposedStart = Carbon::parse($data['proposed_start_date'])->startOfDay();
            $proposedEnd = Carbon::parse($data['proposed_end_date'])->startOfDay();
            $proposedDuration = LeaveDuration::tryFrom((string) ($data['proposed_duration'] ?? LeaveDuration::FullDay->value))
                ?? LeaveDuration::FullDay;

            $this->leaveRequestService->assertValidDateRange($proposedStart, $proposedEnd);
            $this->leaveRequestService->assertDurationMatchesRange($proposedDuration, $proposedStart, $proposedEnd);

            $previousStart = $lockedLeave->start_date->copy()->startOfDay();
            $previousEnd = $lockedLeave->end_date->copy()->startOfDay();

            $this->assertPayrollWritableForAmendment(
                previousStart: $previousStart,
                previousEnd: $previousEnd,
                proposedStart: $proposedStart,
                proposedEnd: $proposedEnd,
            );

            $this->leaveRequestService->assertNoOverlappingLeaveForAmendment(
                user: $lockedLeave->user,
                startDate: $proposedStart,
                endDate: $proposedEnd,
                excludeLeaveRequestId: $lockedLeave->id,
            );

            $amendment = LeaveRequestAmendment::query()->create([
                'leave_request_id' => $lockedLeave->id,
                'type' => LeaveAmendmentType::DateChange,
                'source' => LeaveAmendmentSource::HrDirect,
                'requested_by' => $manager->id,
                'previous_start_date' => $lockedLeave->start_date->toDateString(),
                'previous_end_date' => $lockedLeave->end_date->toDateString(),
                'previous_duration' => $lockedLeave->duration->value,
                'proposed_start_date' => $proposedStart->toDateString(),
                'proposed_end_date' => $proposedEnd->toDateString(),
                'proposed_duration' => $proposedDuration->value,
                'reason' => $data['reason'],
                'status' => LeaveAmendmentStatus::Approved,
                'reviewed_by' => $manager->id,
                'reviewed_at' => now(),
                'review_notes' => $data['review_notes'],
            ]);

            $this->applyApprovedAmendment($lockedLeave, $amendment, $manager);

            $amendment = $amendment->fresh(['leaveRequest', 'requester', 'reviewer']);

            $this->auditAmendmentEvent(
                event: WorkforceAuditEvent::LeaveAmendmentApproved,
                userId: $manager->id,
                amendment: $amendment,
            );

            return $amendment;
        });
    }

    /**
     * @param  array{reason: string, review_notes: string}  $data
     */
    public function manageCancellation(User $manager, LeaveRequest $leaveRequest, array $data): LeaveRequestAmendment
    {
        $this->assertCanManage($manager);

        return DB::transaction(function () use ($manager, $leaveRequest, $data): LeaveRequestAmendment {
            $lockedLeave = $this->lockLeaveRequest($leaveRequest);
            $this->assertManageableApprovedLeave($lockedLeave);

            $previousStart = $lockedLeave->start_date->copy()->startOfDay();
            $previousEnd = $lockedLeave->end_date->copy()->startOfDay();

            $this->assertPayrollWritableForAmendment(
                previousStart: $previousStart,
                previousEnd: $previousEnd,
                proposedStart: null,
                proposedEnd: null,
            );

            $amendment = LeaveRequestAmendment::query()->create([
                'leave_request_id' => $lockedLeave->id,
                'type' => LeaveAmendmentType::Cancellation,
                'source' => LeaveAmendmentSource::HrDirect,
                'requested_by' => $manager->id,
                'previous_start_date' => $lockedLeave->start_date->toDateString(),
                'previous_end_date' => $lockedLeave->end_date->toDateString(),
                'previous_duration' => $lockedLeave->duration->value,
                'proposed_start_date' => null,
                'proposed_end_date' => null,
                'proposed_duration' => null,
                'reason' => $data['reason'],
                'status' => LeaveAmendmentStatus::Approved,
                'reviewed_by' => $manager->id,
                'reviewed_at' => now(),
                'review_notes' => $data['review_notes'],
            ]);

            $this->applyApprovedAmendment($lockedLeave, $amendment, $manager);

            $amendment = $amendment->fresh(['leaveRequest', 'requester', 'reviewer']);

            $this->auditAmendmentEvent(
                event: WorkforceAuditEvent::LeaveAmendmentApproved,
                userId: $manager->id,
                amendment: $amendment,
            );

            return $amendment;
        });
    }

    /**
     * @return Collection<int, LeaveRequestAmendment>
     */
    public function pendingAmendments(): Collection
    {
        return LeaveRequestAmendment::query()
            ->with(['leaveRequest.user', 'requester'])
            ->where('status', LeaveAmendmentStatus::Pending)
            ->where('source', LeaveAmendmentSource::AgentRequest)
            ->orderBy('created_at')
            ->get();
    }

    private function applyApprovedAmendment(
        LeaveRequest $leaveRequest,
        LeaveRequestAmendment $amendment,
        User $actor,
    ): void {
        $previousStart = $leaveRequest->start_date->copy()->startOfDay();
        $previousEnd = $leaveRequest->end_date->copy()->startOfDay();

        if ($amendment->type === LeaveAmendmentType::Cancellation) {
            $leaveRequest->fill([
                'status' => LeaveRequestStatus::Cancelled,
            ])->save();

            $this->auditLeaveUpdated(
                actor: $actor,
                leaveRequest: $leaveRequest->fresh(['user']),
                previousStart: $previousStart,
                previousEnd: $previousEnd,
                event: WorkforceAuditEvent::LeaveCancelled,
            );

            $this->refreshAttendanceUnion($leaveRequest->user, $previousStart, $previousEnd, $previousStart, $previousEnd);
            $this->publishLeaveEvent(WorkforceEventType::LeaveCancelled, $leaveRequest);

            return;
        }

        $proposedStart = $amendment->proposed_start_date->copy()->startOfDay();
        $proposedEnd = $amendment->proposed_end_date->copy()->startOfDay();
        $proposedDuration = $amendment->proposed_duration ?? LeaveDuration::FullDay;

        $leaveRequest->fill([
            'start_date' => $proposedStart->toDateString(),
            'end_date' => $proposedEnd->toDateString(),
            'duration' => $proposedDuration,
        ])->save();

        $leaveRequest = $leaveRequest->fresh(['user']);

        $this->auditLeaveUpdated(
            actor: $actor,
            leaveRequest: $leaveRequest,
            previousStart: $previousStart,
            previousEnd: $previousEnd,
            event: WorkforceAuditEvent::LeaveUpdated,
        );

        $this->refreshAttendanceUnion(
            user: $leaveRequest->user,
            previousStart: $previousStart,
            previousEnd: $previousEnd,
            proposedStart: $proposedStart,
            proposedEnd: $proposedEnd,
        );
    }

    private function refreshAttendanceUnion(
        ?User $user,
        Carbon $previousStart,
        Carbon $previousEnd,
        Carbon $proposedStart,
        Carbon $proposedEnd,
    ): void {
        if ($user === null) {
            return;
        }

        $refreshStart = $previousStart->lt($proposedStart) ? $previousStart : $proposedStart;
        $refreshEnd = $previousEnd->gt($proposedEnd) ? $previousEnd : $proposedEnd;

        $this->attendanceRegisterService->refreshDateRange(
            user: $user,
            startDate: $refreshStart,
            endDate: $refreshEnd,
        );
    }

    /**
     * @param  array{type: string, reason: string, proposed_start_date?: string, proposed_end_date?: string, proposed_duration?: string}  $data
     * @return array{0: ?Carbon, 1: ?Carbon, 2: ?LeaveDuration}
     */
    private function resolveProposedDates(
        LeaveAmendmentType $type,
        LeaveRequest $leaveRequest,
        array $data,
    ): array {
        if ($type === LeaveAmendmentType::Cancellation) {
            return [null, null, null];
        }

        $proposedStart = Carbon::parse($data['proposed_start_date'])->startOfDay();
        $proposedEnd = Carbon::parse($data['proposed_end_date'])->startOfDay();
        $proposedDuration = LeaveDuration::tryFrom((string) ($data['proposed_duration'] ?? $leaveRequest->duration->value))
            ?? $leaveRequest->duration;

        $this->leaveRequestService->assertValidDateRange($proposedStart, $proposedEnd);
        $this->leaveRequestService->assertDurationMatchesRange($proposedDuration, $proposedStart, $proposedEnd);
        $this->leaveRequestService->assertPermittedStartDate($proposedStart);

        return [$proposedStart, $proposedEnd, $proposedDuration];
    }

    private function assertAmendableByAgent(User $requester, LeaveRequest $leaveRequest): void
    {
        if ((int) $leaveRequest->user_id !== (int) $requester->id) {
            throw ValidationException::withMessages([
                'leave_request' => 'You can only request amendments for your own leave.',
            ]);
        }

        if ($leaveRequest->status !== LeaveRequestStatus::Approved) {
            throw ValidationException::withMessages([
                'status' => 'Only approved leave requests can be amended.',
            ]);
        }
    }

    private function assertManageableApprovedLeave(LeaveRequest $leaveRequest): void
    {
        if ($leaveRequest->status !== LeaveRequestStatus::Approved) {
            throw ValidationException::withMessages([
                'status' => 'Only approved leave requests can be managed.',
            ]);
        }

        if ($leaveRequest->hasPendingAmendment()) {
            throw ValidationException::withMessages([
                'amendment' => 'This leave request already has a pending amendment.',
            ]);
        }
    }

    private function assertNoPendingAmendment(LeaveRequest $leaveRequest): void
    {
        $hasPending = LeaveRequestAmendment::query()
            ->where('leave_request_id', $leaveRequest->id)
            ->where('status', LeaveAmendmentStatus::Pending)
            ->exists();

        if ($hasPending) {
            throw ValidationException::withMessages([
                'amendment' => 'This leave request already has a pending amendment.',
            ]);
        }
    }

    private function assertPayrollWritableForAmendment(
        Carbon $previousStart,
        Carbon $previousEnd,
        ?Carbon $proposedStart,
        ?Carbon $proposedEnd,
    ): void {
        $this->payrollMonthLockService->assertLeaveWritable($previousStart, $previousEnd);

        if ($proposedStart !== null && $proposedEnd !== null) {
            $this->payrollMonthLockService->assertLeaveWritable($proposedStart, $proposedEnd);
        }
    }

    private function assertCanManage(User $user): void
    {
        if (! $this->canManage($user)) {
            throw ValidationException::withMessages([
                'manager' => 'You are not allowed to manage leave requests.',
            ]);
        }
    }

    public function assertCanReviewAmendment(User $reviewer, LeaveRequestAmendment $amendment): void
    {
        if (! $this->canReviewAmendment($reviewer, $amendment)) {
            throw ValidationException::withMessages([
                'reviewer' => 'You are not allowed to review this leave amendment.',
            ]);
        }
    }

    private function lockLeaveRequest(LeaveRequest $leaveRequest): LeaveRequest
    {
        return LeaveRequest::query()
            ->whereKey($leaveRequest->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function lockAmendment(LeaveRequestAmendment $amendment): LeaveRequestAmendment
    {
        $locked = LeaveRequestAmendment::query()
            ->whereKey($amendment->id)
            ->lockForUpdate()
            ->firstOrFail();

        if ($locked->status !== LeaveAmendmentStatus::Pending) {
            throw ValidationException::withMessages([
                'status' => 'Only pending amendments can be reviewed.',
            ]);
        }

        return $locked;
    }

    private function assertReviewNotesProvided(?string $reviewNotes): void
    {
        if (blank($reviewNotes)) {
            throw ValidationException::withMessages([
                'review_notes' => 'A review note is required when approving or rejecting an amendment.',
            ]);
        }
    }

    private function auditAmendmentEvent(
        WorkforceAuditEvent $event,
        int $userId,
        LeaveRequestAmendment $amendment,
    ): void {
        $this->auditLogService->log(
            userId: $userId,
            event: $event->value,
            auditable: $amendment->leaveRequest,
            newValues: [
                'amendment_id' => $amendment->id,
                'amendment_type' => $amendment->type->value,
                'amendment_source' => $amendment->source->value,
                'amendment_status' => $amendment->status->value,
                'requested_by' => $amendment->requested_by,
                'previous_start_date' => $amendment->previous_start_date->toDateString(),
                'previous_end_date' => $amendment->previous_end_date->toDateString(),
                'previous_duration' => $amendment->previous_duration->value,
                'proposed_start_date' => $amendment->proposed_start_date?->toDateString(),
                'proposed_end_date' => $amendment->proposed_end_date?->toDateString(),
                'proposed_duration' => $amendment->proposed_duration?->value,
                'reason' => $amendment->reason,
                'reviewed_by' => $amendment->reviewed_by,
                'review_notes' => $amendment->review_notes,
                'legacy_event' => $event->legacyEvent(),
            ],
        );
    }

    private function auditLeaveUpdated(
        User $actor,
        LeaveRequest $leaveRequest,
        Carbon $previousStart,
        Carbon $previousEnd,
        WorkforceAuditEvent $event,
    ): void {
        $this->auditLogService->log(
            userId: $actor->id,
            event: $event->value,
            auditable: $leaveRequest,
            newValues: [
                'actor_id' => $actor->id,
                'previous_start_date' => $previousStart->toDateString(),
                'previous_end_date' => $previousEnd->toDateString(),
                'new_start_date' => $leaveRequest->start_date->toDateString(),
                'new_end_date' => $leaveRequest->end_date->toDateString(),
                'new_status' => $leaveRequest->status->value,
                'legacy_event' => $event->legacyEvent(),
            ],
        );
    }

    private function publishLeaveEvent(WorkforceEventType $type, LeaveRequest $leaveRequest): void
    {
        $this->workforceEventPublisher->publish(WorkforceEvent::make(
            type: $type,
            userId: (int) $leaveRequest->user_id,
            workDate: $leaveRequest->start_date->copy()->startOfDay(),
            payload: [
                'leave_request_id' => $leaveRequest->id,
                'start_date' => $leaveRequest->start_date->toDateString(),
                'end_date' => $leaveRequest->end_date->toDateString(),
                'status' => $leaveRequest->status->value,
            ],
        ));
    }

    private function notifyManagersOfSubmission(LeaveRequestAmendment $amendment): void
    {
        foreach ($this->eligibleManagers() as $manager) {
            if ($this->notificationAuthority->shouldDeliver(
                $manager,
                NotificationCategory::LeaveApprovals,
                NotificationChannelType::InApp,
            )) {
                $manager->notify(new LeaveAmendmentSubmittedNotification($amendment));
            }
        }
    }

    private function notifyRequesterOfDecision(LeaveRequestAmendment $amendment): void
    {
        $requester = $amendment->leaveRequest?->user;

        if ($requester === null || ! $requester->is_active) {
            return;
        }

        if ($this->notificationAuthority->shouldDeliver(
            $requester,
            NotificationCategory::LeaveApprovals,
            NotificationChannelType::InApp,
        )) {
            $requester->notify(new LeaveAmendmentDecisionNotification($amendment));
        }
    }

    /**
     * @return Collection<int, User>
     */
    private function eligibleManagers(): Collection
    {
        return User::query()
            ->permission('leave-requests.manage')
            ->where('is_active', true)
            ->get();
    }
}
