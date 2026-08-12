<?php

namespace App\Notifications;

use App\Enums\LeaveAmendmentType;
use App\Models\LeaveRequestAmendment;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class LeaveAmendmentSubmittedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly LeaveRequestAmendment $amendment,
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
        $requester = $this->amendment->requester;
        $requesterName = $requester?->firstName() ?: 'A team member';
        $leaveRequest = $this->amendment->leaveRequest;
        $action = $this->amendment->type === LeaveAmendmentType::Cancellation
            ? 'cancellation'
            : 'change';

        return [
            'title' => 'Leave Amendment Requested',
            'message' => "{$requesterName} requested a leave {$action} for {$leaveRequest?->start_date?->toDateString()} to {$leaveRequest?->end_date?->toDateString()}.",
            'url' => $leaveRequest ? route('leave-requests.show', $leaveRequest) : null,
        ];
    }
}
