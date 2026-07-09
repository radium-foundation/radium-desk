<?php

namespace App\Notifications;

use App\Enums\WaitingReason;
use App\Models\Incident;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ServiceCaseCustomerRespondedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Incident $incident,
        private readonly WaitingReason $waitingReason,
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
            'title' => 'Customer Response Received',
            'message' => "{$this->incident->reference_no}: Customer provided {$this->waitingReason->label()}. Review in My Work.",
            'url' => route('incidents.show', $this->incident),
        ];
    }
}
