<?php

namespace App\Services\Operations;

use App\Enums\LeaveRequestStatus;
use App\Enums\NotificationCategory;
use App\Enums\NotificationChannelType;
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
use Illuminate\Validation\ValidationException;

class LeaveRequestService
{
    public function __construct(
        private readonly NotificationAuthorityService $notificationAuthority,
        private readonly TelegramBotService $telegramBot,
        private readonly AuditLogService $auditLogService,
    ) {}

    /**
     * @param  array{start_date: string, end_date: string, reason: string}  $data
     */
    public function submit(User $requester, array $data): LeaveRequest
    {
        $leaveRequest = LeaveRequest::query()->create([
            'user_id' => $requester->id,
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'reason' => $data['reason'],
            'status' => LeaveRequestStatus::Pending,
        ]);

        $leaveRequest = $leaveRequest->fresh(['user']);

        $this->auditLogService->log(
            userId: $requester->id,
            event: 'leave.submitted',
            auditable: $leaveRequest,
            newValues: [
                'requester_id' => $requester->id,
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'status' => LeaveRequestStatus::Pending->value,
            ],
        );

        $this->notifyApproversOfSubmission($leaveRequest);

        return $leaveRequest;
    }

    public function approve(LeaveRequest $leaveRequest, User $reviewer, ?string $reviewNotes = null): LeaveRequest
    {
        $this->assertCanReview($reviewer, $leaveRequest);
        $this->assertReviewNotesProvided($reviewNotes);

        if ($leaveRequest->status !== LeaveRequestStatus::Pending) {
            throw ValidationException::withMessages([
                'status' => 'Only pending leave requests can be approved.',
            ]);
        }

        $leaveRequest->fill([
            'status' => LeaveRequestStatus::Approved,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'review_notes' => $reviewNotes,
        ])->save();

        $leaveRequest = $leaveRequest->fresh(['user', 'reviewer']);

        $this->auditLeaveDecision($leaveRequest, $reviewer, 'leave.approved');

        $this->notifyRequesterOfDecision($leaveRequest);

        return $leaveRequest;
    }

    public function reject(LeaveRequest $leaveRequest, User $reviewer, ?string $reviewNotes = null): LeaveRequest
    {
        $this->assertCanReview($reviewer, $leaveRequest);
        $this->assertReviewNotesProvided($reviewNotes);

        if ($leaveRequest->status !== LeaveRequestStatus::Pending) {
            throw ValidationException::withMessages([
                'status' => 'Only pending leave requests can be rejected.',
            ]);
        }

        $leaveRequest->fill([
            'status' => LeaveRequestStatus::Rejected,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'review_notes' => $reviewNotes,
        ])->save();

        $leaveRequest = $leaveRequest->fresh(['user', 'reviewer']);

        $this->auditLeaveDecision($leaveRequest, $reviewer, 'leave.rejected');

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

    private function auditLeaveDecision(LeaveRequest $leaveRequest, User $reviewer, string $event): void
    {
        $this->auditLogService->log(
            userId: $reviewer->id,
            event: $event,
            auditable: $leaveRequest,
            newValues: [
                'reviewer_id' => $reviewer->id,
                'reviewed_at' => $leaveRequest->reviewed_at?->toIso8601String(),
                'status' => $leaveRequest->status->value,
                'review_notes' => $leaveRequest->review_notes,
            ],
        );
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
            $this->auditLogService->log(
                userId: $leaveRequest->user_id,
                event: 'leave.notification.dispatched',
                auditable: $leaveRequest,
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
            $this->auditLogService->log(
                userId: $leaveRequest->user_id,
                event: 'leave.notification.dispatched',
                auditable: $leaveRequest,
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

        $this->auditLogService->log(
            userId: $leaveRequest->user_id,
            event: 'leave.notification.dispatched',
            auditable: $leaveRequest,
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
