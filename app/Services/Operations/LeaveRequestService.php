<?php

namespace App\Services\Operations;

use App\Enums\LeaveRequestStatus;
use App\Enums\NotificationCategory;
use App\Enums\NotificationChannelType;
use App\Enums\WorkforceAuditEvent;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Notifications\LeaveRequestDecisionNotification;
use App\Notifications\LeaveRequestSubmittedNotification;
use App\Services\AuditLogService;
use App\Services\Notifications\NotificationAuthorityService;
use App\Services\Telegram\TelegramBotService;
use Database\Seeders\RolePermissionSeeder;
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
    ) {}

    public function earliestPermittedStartDate(?Carbon $at = null): Carbon
    {
        $at ??= now();

        $retroactiveDays = max(0, (int) config('workforce_calendar.retroactive_leave_days', 2));

        return $at->copy()->startOfDay()->subDays($retroactiveDays);
    }

    /**
     * @param  array{start_date: string, end_date: string, reason: string}  $data
     */
    public function submit(User $requester, array $data): LeaveRequest
    {
        $startDate = Carbon::parse($data['start_date'])->startOfDay();
        $endDate = Carbon::parse($data['end_date'])->startOfDay();

        $leaveRequest = DB::transaction(function () use ($requester, $data, $startDate, $endDate): LeaveRequest {
            $this->lockActiveLeaveRequestsFor($requester);
            $this->assertPermittedStartDate($startDate);
            $this->assertValidDateRange($startDate, $endDate);
            $this->assertNoOverlappingLeave($requester, $startDate, $endDate);

            $leaveRequest = LeaveRequest::query()->create([
                'user_id' => $requester->id,
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
                'reason' => $data['reason'],
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

            $this->auditLeaveEvent(
                event: WorkforceAuditEvent::LeaveApproved,
                userId: $reviewer->id,
                leaveRequest: $lockedLeaveRequest,
                newValues: [
                    'reviewer_id' => $reviewer->id,
                    'reviewed_at' => $lockedLeaveRequest->reviewed_at?->toIso8601String(),
                    'status' => $lockedLeaveRequest->status->value,
                    'review_notes' => $lockedLeaveRequest->review_notes,
                ],
            );

            return $lockedLeaveRequest;
        });

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

            $lockedLeaveRequest->fill([
                'status' => LeaveRequestStatus::Rejected,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_notes' => $reviewNotes,
            ])->save();

            $lockedLeaveRequest = $lockedLeaveRequest->fresh(['user', 'reviewer']);

            $this->auditLeaveEvent(
                event: WorkforceAuditEvent::LeaveRejected,
                userId: $reviewer->id,
                leaveRequest: $lockedLeaveRequest,
                newValues: [
                    'reviewer_id' => $reviewer->id,
                    'reviewed_at' => $lockedLeaveRequest->reviewed_at?->toIso8601String(),
                    'status' => $lockedLeaveRequest->status->value,
                    'review_notes' => $lockedLeaveRequest->review_notes,
                ],
            );

            return $lockedLeaveRequest;
        });

        $this->notifyRequesterOfDecision($leaveRequest);

        return $leaveRequest;
    }

    public function canReview(User $reviewer, LeaveRequest $leaveRequest): bool
    {
        if (! $reviewer->can('leave-requests.review')) {
            return false;
        }

        $requester = $leaveRequest->user;

        if ($requester === null || $reviewer->id === $requester->id) {
            return false;
        }

        if ($requester->hasAnyRole([
            RolePermissionSeeder::ROLE_OPERATIONS_ADMIN,
            RolePermissionSeeder::ROLE_ADMIN,
        ])) {
            return $reviewer->hasRole(RolePermissionSeeder::ROLE_SUPERADMIN);
        }

        if ($requester->hasAnyRole($this->employeeLeaveRequesterRoles())) {
            return $reviewer->hasRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);
        }

        return false;
    }

    public function assertCanReview(User $reviewer, LeaveRequest $leaveRequest): void
    {
        if (! $this->canReview($reviewer, $leaveRequest)) {
            throw ValidationException::withMessages([
                'reviewer' => 'You are not allowed to review this leave request.',
            ]);
        }
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
        $requester = $leaveRequest->user;

        if ($requester === null) {
            return collect();
        }

        $approverRoles = $requester->hasAnyRole([
            RolePermissionSeeder::ROLE_OPERATIONS_ADMIN,
            RolePermissionSeeder::ROLE_ADMIN,
        ])
            ? [RolePermissionSeeder::ROLE_SUPERADMIN]
            : [RolePermissionSeeder::ROLE_OPERATIONS_ADMIN];

        return User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->whereIn('name', $approverRoles))
            ->get()
            ->filter(fn (User $reviewer): bool => $this->canReview($reviewer, $leaveRequest))
            ->values();
    }

    private function assertReviewNotesProvided(?string $reviewNotes): void
    {
        if (blank($reviewNotes)) {
            throw ValidationException::withMessages([
                'review_notes' => 'A review note is required when approving or rejecting leave.',
            ]);
        }
    }

    /**
     * @return list<string>
     */
    private function employeeLeaveRequesterRoles(): array
    {
        return [
            RolePermissionSeeder::ROLE_SUPPORT_SPECIALIST,
            RolePermissionSeeder::ROLE_CUSTOMER_COORDINATOR,
            RolePermissionSeeder::ROLE_HARDWARE_TEAM,
            RolePermissionSeeder::ROLE_AGENT,
            RolePermissionSeeder::ROLE_ESCALATION_SPECIALIST,
        ];
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
