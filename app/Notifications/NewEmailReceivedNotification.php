<?php

namespace App\Notifications;

use App\Models\Incident;
use App\Models\IncomingEmailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class NewEmailReceivedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Incident $incident,
        private readonly IncomingEmailMessage $message,
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
        $subject = trim((string) ($this->message->subject ?? ''));
        $subjectLabel = $subject !== '' ? $subject : 'Incoming email';

        return [
            'title' => 'New Email Received',
            'message' => "{$this->incident->reference_no}: {$subjectLabel}. Open communication to reply.",
            'url' => route('incidents.show', $this->incident),
            'incoming_email_message_id' => $this->message->id,
            'action_label' => 'Open Communication',
        ];
    }
}
