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
        $durationLabel = $this->leaveRequest->duration?->label() ?? 'Full Day';
        $datesLabel = $startDate === $endDate
            ? $startDate
            : "{$startDate} to {$endDate}";
        $decision = match ($this->leaveRequest->status) {
            LeaveRequestStatus::Approved => 'approved',
            LeaveRequestStatus::Rejected => 'rejected',
            default => 'updated',
        };

        $message = "Your {$durationLabel} leave request ({$datesLabel}) was {$decision} by {$reviewerName}.";

        if (
            $this->leaveRequest->status === LeaveRequestStatus::Rejected
            && filled($this->leaveRequest->review_notes)
        ) {
            $message .= ' Reason: '.$this->leaveRequest->review_notes;
        }

        return [
            'title' => 'Leave Request '.ucfirst($decision),
            'message' => $message,
            'url' => route('leave-requests.show', $this->leaveRequest),
        ];
    }
}
