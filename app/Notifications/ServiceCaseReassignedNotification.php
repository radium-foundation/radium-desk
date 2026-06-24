<?php

namespace App\Notifications;

use App\Models\Incident;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ServiceCaseReassignedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Incident $incident,
        private readonly User $reassignedBy,
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
            'title' => 'Service Case Reassigned',
            'message' => "{$this->incident->reference_no} was reassigned to you by {$this->reassignedBy->firstName()}.",
            'url' => route('incidents.show', $this->incident),
        ];
    }
}
