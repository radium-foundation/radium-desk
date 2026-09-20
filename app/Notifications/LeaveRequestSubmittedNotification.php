<?php

namespace App\Notifications;

use App\Models\LeaveRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class LeaveRequestSubmittedNotification extends Notification
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
        $requester = $this->leaveRequest->user;
        $requesterName = $requester?->firstName() ?: 'A team member';
        $startDate = $this->leaveRequest->start_date->toDateString();
        $endDate = $this->leaveRequest->end_date->toDateString();
        $durationLabel = $this->leaveRequest->duration?->label() ?? 'Full Day';
        $datesLabel = $startDate === $endDate
            ? $startDate
            : "{$startDate} to {$endDate}";

        return [
            'title' => 'Leave Request Submitted',
            'message' => "New leave request from {$requesterName} ({$durationLabel}, {$datesLabel}).",
            'url' => route('leave-requests.show', $this->leaveRequest),
        ];
    }
}
