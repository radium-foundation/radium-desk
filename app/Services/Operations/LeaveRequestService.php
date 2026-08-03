<?php

namespace App\Services\Operations;

use App\Contracts\Workforce\WorkforceEventPublisher;
use App\Data\Workforce\WorkforceEvent;
use App\Enums\LeaveDuration;
use App\Enums\LeavePayClass;
use App\Enums\LeaveRequestStatus;
use App\Enums\NotificationCategory;
use App\Enums\NotificationChannelType;
use App\Enums\WorkforceAuditEvent;
use App\Enums\WorkforceEventType;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Notifications\LeaveRequestDecisionNotification;
use App\Notifications\LeaveRequestSubmittedNotification;
use App\Services\AuditLogService;
use App\Services\Notifications\NotificationAuthorityService;
use App\Services\Telegram\TelegramBotService;
use App\Services\Workforce\PayrollMonthLockService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeaveRequestService
{
    public function __construct(
        private readonly NotificationAuthorityService $notificationAuthority,
        private readonly TelegramBotService $telegramBot,
        private readonly AuditLogService $auditLogService,
        private readonly AttendanceRegisterService $attendanceRegisterService,
        private readonly WorkforceEventPublisher $workforceEventPublisher,
        private readonly PayrollMonthLockService $payrollMonthLockService,
    ) {}

    public function earliestPermittedStartDate(?Carbon $at = null): Carbon
    {
        $at ??= now();

        $retroactiveDays = max(0, (int) config('workforce_calendar.retroactive_leave_days', 2));

        return $at->copy()->startOfDay()->subDays($retroactiveDays);
    }

    /**
     * @param  array{start_date: string, end_date: string, reason: string, duration?: string, pay_class?: string}  $data
     */
    public function submit(User $requester, array $data): LeaveRequest
    {
        $startDate = Carbon::parse($data['start_date'])->startOfDay();
        $endDate = Carbon::parse($data['end_date'])->startOfDay();
        $duration = LeaveDuration::tryFrom((string) ($data['duration'] ?? LeaveDuration::FullDay->value))
            ?? LeaveDuration::FullDay;

        $leaveRequest = DB::transaction(function () use ($requester, $data, $startDate, $endDate, $duration): LeaveRequest {
            $this->lockActiveLeaveRequestsFor($requester);
            $this->payrollMonthLockService->assertLeaveWritable($startDate, $endDate);
            $this->assertPermittedStartDate($startDate);
            $this->assertValidDateRange($startDate, $endDate);
            $this->assertDurationMatchesRange($duration, $startDate, $endDate);
            $this->assertNoOverlappingLeave($requester, $startDate, $endDate);

            $leaveRequest = LeaveRequest::query()->create([
                'user_id' => $requester->id,
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
                'reason' => $data['reason'],
                'duration' => $duration,
                'pay_class' => LeavePayClass::tryFrom((string) ($data['pay_class'] ?? LeavePayClass::Paid->value))
                    ?? LeavePayClass::Paid,
                'status' => LeaveRequestStatus::Pending,
            ]);

            $leaveRequest = $leaveRequest->fresh(['user']);

            $this->auditLeaveEvent(
                event: WorkforceAuditEvent::LeaveSubmitted,
                userId: $requester->id,
                leaveRequest: $leaveRequest,
                newValues: [
                    'requester_id' => $requester->id,
                    'start_date' => $leaveRequest->start_date->toDateString(),
                    'end_date' => $leaveRequest->end_date->toDateString(),
                    'duration' => $leaveRequest->duration->value,
                    'status' => LeaveRequestStatus::Pending->value,
                ],
            );

            return $leaveRequest;
        });

        $this->notifyApproversOfSubmission($leaveRequest);

        return $leaveRequest;
    }

    public function approve(LeaveRequest $leaveRequest, User $reviewer, ?string $reviewNotes = null): LeaveRequest
    {
        $this->assertReviewNotesProvided($reviewNotes);

        $leaveRequest = DB::transaction(function () use ($leaveRequest, $reviewer, $reviewNotes): LeaveRequest {
            $lockedLeaveRequest = $this->lockLeaveRequest($leaveRequest);

            $this->assertCanReview($reviewer, $lockedLeaveRequest);

            if ($lockedLeaveRequest->status !== LeaveRequestStatus::Pending) {
                throw ValidationException::withMessages([
                    'status' => 'Only pending leave requests can be approved.',
                ]);
            }

            $this->payrollMonthLockService->assertLeaveWritable(
                $lockedLeaveRequest->start_date->copy()->startOfDay(),
                $lockedLeaveRequest->end_date->copy()->startOfDay(),
            );

            $requester = $lockedLeaveRequest->user;

            if ($requester !== null) {
                $this->lockActiveLeaveRequestsFor($requester);
                $this->assertNoOverlappingLeave(
                    user: $requester,
                    startDate: $lockedLeaveRequest->start_date->copy()->startOfDay(),
                    endDate: $lockedLeaveRequest->end_date->copy()->startOfDay(),
                    excludeLeaveRequestId: $lockedLeaveRequest->id,
                    statuses: [LeaveRequestStatus::Approved],
                );
            }

            $lockedLeaveRequest->fill([
                'status' => LeaveRequestStatus::Approved,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_notes' => $reviewNotes,
            ])->save();

            $lockedLeaveRequest = $lockedLeaveRequest->fresh(['user', 'reviewer']);
            $selfApproved = (int) $lockedLeaveRequest->user_id === (int) $reviewer->id;

            $this->auditLeaveEvent(
                event: WorkforceAuditEvent::LeaveApproved,
                userId: $reviewer->id,
                leaveRequest: $lockedLeaveRequest,
                newValues: [
                    'reviewer_id' => $reviewer->id,
                    'approved_by' => $reviewer->id,
                    'actor' => $reviewer->id,
                    'self_approved' => $selfApproved,
                    'reviewed_at' => $lockedLeaveRequest->reviewed_at?->toIso8601String(),
                    'status' => $lockedLeaveRequest->status->value,
                    'review_notes' => $lockedLeaveRequest->review_notes,
                ],
            );

            return $lockedLeaveRequest;
        });

        $requester = $leaveRequest->user;

        if ($requester !== null) {
            $this->attendanceRegisterService->refreshDateRange(
                user: $requester,
                startDate: $leaveRequest->start_date->copy()->startOfDay(),
                endDate: $leaveRequest->end_date->copy()->startOfDay(),
            );
        }

        $this->publishLeaveEvent(WorkforceEventType::LeaveApproved, $leaveRequest);
        $this->notifyRequesterOfDecision($leaveRequest);

        return $leaveRequest;
    }

    public function reject(LeaveRequest $leaveRequest, User $reviewer, ?string $reviewNotes = null): LeaveRequest
    {
        $this->assertReviewNotesProvided($reviewNotes);

        $leaveRequest = DB::transaction(function () use ($leaveRequest, $reviewer, $reviewNotes): LeaveRequest {
            $lockedLeaveRequest = $this->lockLeaveRequest($leaveRequest);

            $this->assertCanReview($reviewer, $lockedLeaveRequest);

            if ($lockedLeaveRequest->status !== LeaveRequestStatus::Pending) {
                throw ValidationException::withMessages([
                    'status' => 'Only pending leave requests can be rejected.',
                ]);
            }

            $this->payrollMonthLockService->assertLeaveWritable(
                $lockedLeaveRequest->start_date->copy()->startOfDay(),
                $lockedLeaveRequest->end_date->copy()->startOfDay(),
            );

            $lockedLeaveRequest->fill([
                'status' => LeaveRequestStatus::Rejected,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_notes' => $reviewNotes,
            ])->save();

            $lockedLeaveRequest = $lockedLeaveRequest->fresh(['user', 'reviewer']);
            $selfRejected = (int) $lockedLeaveRequest->user_id === (int) $reviewer->id;

            $this->auditLeaveEvent(
                event: WorkforceAuditEvent::LeaveRejected,
                userId: $reviewer->id,
                leaveRequest: $lockedLeaveRequest,
                newValues: [
                    'reviewer_id' => $reviewer->id,
                    'rejected_by' => $reviewer->id,
                    'actor' => $reviewer->id,
                    'self_rejected' => $selfRejected,
                    'reviewed_at' => $lockedLeaveRequest->reviewed_at?->toIso8601String(),
                    'status' => $lockedLeaveRequest->status->value,
                    'review_notes' => $lockedLeaveRequest->review_notes,
                ],
            );

            return $lockedLeaveRequest;
        });

        $this->publishLeaveEvent(WorkforceEventType::LeaveRejected, $leaveRequest);
        $this->notifyRequesterOfDecision($leaveRequest);

        return $leaveRequest;
    }

    public function canReview(User $reviewer, LeaveRequest $leaveRequest): bool
    {
        if (! $reviewer->can('leave-requests.review')) {
            return false;
        }

        if (! $this->isDesignatedApprover($reviewer)) {
            return false;
        }

        $requester = $leaveRequest->user;

        if ($requester === null) {
            return false;
        }

        // Leave Authority may review every request, including their own.
        // Self-approval remains blocked for every non-designated user (they never reach here).
        return true;
    }

    public function assertCanReview(User $reviewer, LeaveRequest $leaveRequest): void
    {
        if (! $this->canReview($reviewer, $leaveRequest)) {
            throw ValidationException::withMessages([
                'reviewer' => 'You are not allowed to review this leave request.',
            ]);
        }
    }

    public function designatedApproverEmail(): string
    {
        return strtolower(trim((string) config('workforce.leave_approver.email', '')));
    }

    public function isDesignatedApprover(User $user): bool
    {
        $email = $this->designatedApproverEmail();

        if ($email === '' || ! $user->is_active || $user->trashed()) {
            return false;
        }

        return strcasecmp((string) $user->email, $email) === 0;
    }

    public function designatedApprover(): ?User
    {
        $email = $this->designatedApproverEmail();

        if ($email === '') {
            return null;
        }

        return User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->where('is_active', true)
            ->first();
    }

    /**
     * Pending leave rows for the action-first approvals surfaces.
     *
     * @return Collection<int, LeaveRequest>
     */
    public function pendingApprovals(?Carbon $at = null): Collection
    {
        $at ??= now();

        return LeaveRequest::query()
            ->with(['user'])
            ->where('status', LeaveRequestStatus::Pending)
            ->orderBy('start_date')
            ->orderBy('id')
            ->get()
            ->sortBy(function (LeaveRequest $leaveRequest) use ($at): array {
                $coversToday = $this->pendingCoversDay($leaveRequest, $at) ? 0 : 1;

                return [
                    $coversToday,
                    $leaveRequest->start_date?->timestamp ?? 0,
                    $leaveRequest->id,
                ];
            })
            ->values();
    }

    /**
     * @return array{today: Collection<int, LeaveRequest>, upcoming: Collection<int, LeaveRequest>}
     */
    public function pendingApprovalsGrouped(?Carbon $at = null): array
    {
        $at ??= now();
        $pending = $this->pendingApprovals($at);

        return [
            'today' => $pending
                ->filter(fn (LeaveRequest $leaveRequest): bool => $this->pendingCoversDay($leaveRequest, $at))
                ->values(),
            'upcoming' => $pending
                ->reject(fn (LeaveRequest $leaveRequest): bool => $this->pendingCoversDay($leaveRequest, $at))
                ->values(),
        ];
    }

    public function pendingCoversDay(LeaveRequest $leaveRequest, ?Carbon $at = null): bool
    {
        $at ??= now();
        $day = $at->copy()->startOfDay();

        if ($leaveRequest->start_date === null || $leaveRequest->end_date === null) {
            return false;
        }

        return $leaveRequest->start_date->copy()->startOfDay()->lte($day)
            && $leaveRequest->end_date->copy()->startOfDay()->gte($day);
    }

    public function pendingAgeLabel(LeaveRequest $leaveRequest, ?Carbon $at = null): string
    {
        $at ??= now();
        $submittedAt = $leaveRequest->created_at;

        if ($submittedAt === null) {
            return 'Submitted recently';
        }

        $days = (int) $submittedAt->copy()->startOfDay()->diffInDays($at->copy()->startOfDay());

        return match (true) {
            $days <= 0 => 'Submitted today',
            $days === 1 => 'Submitted 1 day ago',
            default => "Submitted {$days} days ago",
        };
    }

    public function leaveDatesLabel(LeaveRequest $leaveRequest): string
    {
        $start = $leaveRequest->start_date;
        $end = $leaveRequest->end_date;

        if ($start === null || $end === null) {
            return '—';
        }

        if ($start->isSameDay($end)) {
            if ($start->isToday()) {
                return 'Today';
            }

            return $start->format('j M');
        }

        return $start->format('j M').'–'.$end->format('j M');
    }

    public function coversDate(LeaveRequest $leaveRequest, Carbon $date): bool
    {
        if ($leaveRequest->status !== LeaveRequestStatus::Approved) {
            return false;
        }

        $day = $date->copy()->startOfDay();

        return $day->gte($leaveRequest->start_date->startOfDay())
            && $day->lte($leaveRequest->end_date->endOfDay());
    }

    private function lockLeaveRequest(LeaveRequest $leaveRequest): LeaveRequest
    {
        return LeaveRequest::query()
            ->whereKey($leaveRequest->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function lockActiveLeaveRequestsFor(User $user): void
    {
        LeaveRequest::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [
                LeaveRequestStatus::Pending->value,
                LeaveRequestStatus::Approved->value,
            ])
            ->lockForUpdate()
            ->get();
    }

    /**
     * @param  list<LeaveRequestStatus>  $statuses
     */
    private function assertNoOverlappingLeave(
        User $user,
        Carbon $startDate,
        Carbon $endDate,
        ?int $excludeLeaveRequestId = null,
        ?array $statuses = null,
    ): void {
        $statuses ??= [LeaveRequestStatus::Pending, LeaveRequestStatus::Approved];

        $statusValues = array_map(
            static fn (LeaveRequestStatus $status): string => $status->value,
            $statuses,
        );

        $overlapExists = LeaveRequest::query()
            ->where('user_id', $user->id)
            ->whereIn('status', $statusValues)
            ->when(
                $excludeLeaveRequestId !== null,
                fn ($query) => $query->where('id', '!=', $excludeLeaveRequestId),
            )
            ->whereDate('start_date', '<=', $endDate->toDateString())
            ->whereDate('end_date', '>=', $startDate->toDateString())
            ->exists();

        if ($overlapExists) {
            throw ValidationException::withMessages([
                'start_date' => 'This leave request overlaps an existing pending or approved leave request.',
            ]);
        }
    }

    private function assertPermittedStartDate(Carbon $startDate): void
    {
        if ($startDate->lt($this->earliestPermittedStartDate())) {
            throw ValidationException::withMessages([
                'start_date' => 'Leave cannot start before '.$this->earliestPermittedStartDate()->toDateString().'.',
            ]);
        }
    }

    private function assertValidDateRange(Carbon $startDate, Carbon $endDate): void
    {
        if ($endDate->lt($startDate)) {
            throw ValidationException::withMessages([
                'end_date' => 'The end date must be on or after the start date.',
            ]);
        }
    }

    private function assertDurationMatchesRange(
        LeaveDuration $duration,
        Carbon $startDate,
        Carbon $endDate,
    ): void {
        if ($duration === LeaveDuration::HalfDay && ! $startDate->isSameDay($endDate)) {
            throw ValidationException::withMessages([
                'duration' => 'Half day leave must be for a single date.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $newValues
     */
    private function auditLeaveEvent(
        WorkforceAuditEvent $event,
        int $userId,
        LeaveRequest $leaveRequest,
        array $newValues,
    ): void {
        $this->auditLogService->log(
            userId: $userId,
            event: $event->value,
            auditable: $leaveRequest,
            newValues: [
                ...$newValues,
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
                'reviewed_by' => $leaveRequest->reviewed_by,
            ],
        ));
    }

    private function notifyApproversOfSubmission(LeaveRequest $leaveRequest): void
    {
        foreach ($this->eligibleApprovers($leaveRequest) as $approver) {
            $this->dispatchLeaveNotification(
                recipient: $approver,
                leaveRequest: $leaveRequest,
                inAppNotification: new LeaveRequestSubmittedNotification($leaveRequest),
                telegramTitle: 'Leave Request Submitted',
                telegramMessage: $this->formatSubmittedTelegramMessage($leaveRequest),
            );
        }
    }

    private function notifyRequesterOfDecision(LeaveRequest $leaveRequest): void
    {
        $requester = $leaveRequest->user;

        if ($requester === null || ! $requester->is_active) {
            return;
        }

        $this->dispatchLeaveNotification(
            recipient: $requester,
            leaveRequest: $leaveRequest,
            inAppNotification: new LeaveRequestDecisionNotification($leaveRequest),
            telegramTitle: $this->decisionTelegramTitle($leaveRequest),
            telegramMessage: $this->formatDecisionTelegramMessage($leaveRequest),
        );
    }

    /**
     * @return Collection<int, User>
     */
    private function eligibleApprovers(LeaveRequest $leaveRequest): Collection
    {
        $approver = $this->designatedApprover();

        if ($approver === null || ! $this->canReview($approver, $leaveRequest)) {
            return collect();
        }

        // Do not notify the Leave Authority about her own submission.
        if ((int) $leaveRequest->user_id === (int) $approver->id) {
            return collect();
        }

        return collect([$approver]);
    }

    private function assertReviewNotesProvided(?string $reviewNotes): void
    {
        if (blank($reviewNotes)) {
            throw ValidationException::withMessages([
                'review_notes' => 'A review note is required when approving or rejecting leave.',
            ]);
        }
    }

    private function dispatchLeaveNotification(
        User $recipient,
        LeaveRequest $leaveRequest,
        LeaveRequestSubmittedNotification|LeaveRequestDecisionNotification $inAppNotification,
        string $telegramTitle,
        string $telegramMessage,
    ): void {
        if ($this->notificationAuthority->shouldDeliver(
            $recipient,
            NotificationCategory::LeaveApprovals,
            NotificationChannelType::InApp,
        )) {
            $recipient->notify($inAppNotification);
        }

        $this->dispatchTelegramNotification(
            recipient: $recipient,
            leaveRequest: $leaveRequest,
            title: $telegramTitle,
            message: $telegramMessage,
        );
    }

    private function dispatchTelegramNotification(
        User $recipient,
        LeaveRequest $leaveRequest,
        string $title,
        string $message,
    ): void {
        if (! $this->notificationAuthority->shouldDeliver(
            $recipient,
            NotificationCategory::LeaveApprovals,
            NotificationChannelType::Telegram,
        )) {
            $this->auditLeaveEvent(
                event: WorkforceAuditEvent::LeaveNotificationDispatched,
                userId: $leaveRequest->user_id,
                leaveRequest: $leaveRequest,
                newValues: [
                    'recipient_id' => $recipient->id,
                    'channel' => NotificationChannelType::Telegram->value,
                    'status' => 'skipped',
                    'title' => $title,
                    'message' => 'Telegram delivery blocked by notification authority.',
                ],
            );

            return;
        }

        if (! $this->telegramBot->isConfigured()) {
            $this->auditLeaveEvent(
                event: WorkforceAuditEvent::LeaveNotificationDispatched,
                userId: $leaveRequest->user_id,
                leaveRequest: $leaveRequest,
                newValues: [
                    'recipient_id' => $recipient->id,
                    'channel' => NotificationChannelType::Telegram->value,
                    'status' => 'failed',
                    'title' => $title,
                    'message' => 'Telegram bot token is not configured.',
                ],
            );

            return;
        }

        $sendResult = $this->telegramBot->sendMessage(
            chatId: (string) $recipient->telegram_chat_id,
            text: $message,
        );

        $this->auditLeaveEvent(
            event: WorkforceAuditEvent::LeaveNotificationDispatched,
            userId: $leaveRequest->user_id,
            leaveRequest: $leaveRequest,
            newValues: [
                'recipient_id' => $recipient->id,
                'channel' => NotificationChannelType::Telegram->value,
                'status' => $sendResult->success ? 'sent' : 'failed',
                'title' => $title,
                'message' => $sendResult->success ? null : $sendResult->error,
            ],
        );
    }

    private function formatSubmittedTelegramMessage(LeaveRequest $leaveRequest): string
    {
        $requester = $leaveRequest->user;
        $requesterName = $requester?->firstName() ?: 'A team member';
        $startDate = $leaveRequest->start_date->toDateString();
        $endDate = $leaveRequest->end_date->toDateString();

        return implode("\n", [
            'Leave Request Submitted',
            '',
            "{$requesterName} requested leave.",
            "Dates: {$startDate} to {$endDate}",
            'Reason: '.$leaveRequest->reason,
            '',
            'Review in Radium Desk.',
        ]);
    }

    private function formatDecisionTelegramMessage(LeaveRequest $leaveRequest): string
    {
        $reviewer = $leaveRequest->reviewer;
        $reviewerName = $reviewer?->firstName() ?: 'Operations';
        $startDate = $leaveRequest->start_date->toDateString();
        $endDate = $leaveRequest->end_date->toDateString();
        $decision = match ($leaveRequest->status) {
            LeaveRequestStatus::Approved => 'approved',
            LeaveRequestStatus::Rejected => 'rejected',
            default => 'updated',
        };

        return implode("\n", [
            'Leave Request '.ucfirst($decision),
            '',
            "Your leave request ({$startDate} to {$endDate}) was {$decision} by {$reviewerName}.",
            '',
            'View in Radium Desk.',
        ]);
    }

    private function decisionTelegramTitle(LeaveRequest $leaveRequest): string
    {
        return match ($leaveRequest->status) {
            LeaveRequestStatus::Approved => 'Leave Request Approved',
            LeaveRequestStatus::Rejected => 'Leave Request Rejected',
            default => 'Leave Request Updated',
        };
    }
}
