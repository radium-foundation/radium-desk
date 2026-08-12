<?php

namespace App\Notifications;

use App\Enums\LeaveAmendmentStatus;
use App\Enums\LeaveAmendmentType;
use App\Models\LeaveRequestAmendment;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class LeaveAmendmentDecisionNotification extends Notification
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
        $reviewer = $this->amendment->reviewer;
        $reviewerName = $reviewer?->firstName() ?: 'Operations';
        $leaveRequest = $this->amendment->leaveRequest;
        $decision = $this->amendment->status === LeaveAmendmentStatus::Approved ? 'approved' : 'rejected';
        $action = $this->amendment->type === LeaveAmendmentType::Cancellation
            ? 'cancellation request'
            : 'change request';

        return [
            'title' => 'Leave Amendment '.ucfirst($decision),
            'message' => "Your leave {$action} was {$decision} by {$reviewerName}.",
            'url' => $leaveRequest ? route('leave-requests.show', $leaveRequest) : null,
        ];
    }
}
