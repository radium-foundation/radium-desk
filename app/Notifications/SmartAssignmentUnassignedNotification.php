<?php

namespace App\Notifications;

use App\Data\Operations\SmartAssignmentResult;
use App\Models\Incident;
use App\Models\SupportAppointment;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class SmartAssignmentUnassignedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Incident $incident,
        private readonly SupportAppointment $appointment,
        private readonly SmartAssignmentResult $result,
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
        $slot = $this->appointment->preferred_time_slot?->label() ?? 'Unknown slot';
        $date = $this->appointment->preferred_date?->format('d M Y') ?? 'Unknown date';

        return [
            'title' => 'Support Appointment Unassigned',
            'message' => "{$this->incident->reference_no} was booked for {$date} ({$slot}) but no team member was available for smart assignment.",
            'url' => route('incidents.show', $this->incident),
        ];
    }
}
