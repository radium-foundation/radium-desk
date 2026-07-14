<?php

namespace App\Notifications;

use App\Models\RefundRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class RefundRequestSubmittedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly RefundRequest $refund,
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
        $requester = $this->refund->requester?->name ?? 'An agent';

        return [
            'title' => 'Refund Request Submitted',
            'message' => "{$requester} submitted refund {$this->refund->reference_no} for review.",
            'url' => route('refunds.show', $this->refund),
        ];
    }
}
