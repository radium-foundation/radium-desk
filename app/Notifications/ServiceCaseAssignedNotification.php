<?php

namespace App\Notifications;

use App\Models\Incident;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ServiceCaseAssignedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Incident $incident,
        private readonly User $assignedBy,
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
        return [
            'title' => 'Service Case Assigned',
            'message' => "{$this->incident->reference_no} was assigned to you by {$this->assignedBy->firstName()}.",
            'url' => route('incidents.show', $this->incident),
        ];
    }
}
