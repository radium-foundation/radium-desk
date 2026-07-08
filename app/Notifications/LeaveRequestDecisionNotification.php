<?php

namespace App\Notifications;

use App\Enums\LeaveRequestStatus;
use App\Models\LeaveRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class LeaveRequestDecisionNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly LeaveRequest $leaveRequest,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $reviewer = $this->leaveRequest->reviewer;
        $reviewerName = $reviewer?->firstName() ?: 'Operations';
        $startDate = $this->leaveRequest->start_date->toDateString();
        $endDate = $this->leaveRequest->end_date->toDateString();
        $decision = match ($this->leaveRequest->status) {
            LeaveRequestStatus::Approved => 'approved',
            LeaveRequestStatus::Rejected => 'rejected',
            default => 'updated',
        };

        return [
            'title' => 'Leave Request '.ucfirst($decision),
            'message' => "Your leave request ({$startDate} to {$endDate}) was {$decision} by {$reviewerName}.",
            'url' => route('leave-requests.show', $this->leaveRequest),
        ];
    }
}
