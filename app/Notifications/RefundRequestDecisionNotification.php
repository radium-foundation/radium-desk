<?php

namespace App\Notifications;

use App\Enums\RefundStatus;
use App\Models\RefundRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class RefundRequestDecisionNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly RefundRequest $refund,
        private readonly string $trigger = 'decision',
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
        $reference = $this->refund->reference_no;

        return match ($this->refund->status) {
            RefundStatus::Rejected => [
                'title' => 'Refund Request Rejected',
                'message' => "Refund {$reference} was rejected.",
                'url' => route('refunds.show', $this->refund),
            ],
            RefundStatus::Completed, RefundStatus::Closed => [
                'title' => 'Refund Completed',
                'message' => "Refund {$reference} was marked completed.",
                'url' => route('refunds.show', $this->refund),
            ],
            RefundStatus::PendingExecution => [
                'title' => 'Refund Approved',
                'message' => "Refund {$reference} was approved and is pending execution.",
                'url' => route('refunds.show', $this->refund),
            ],
            default => [
                'title' => 'Refund Updated',
                'message' => "Refund {$reference} was updated.",
                'url' => route('refunds.show', $this->refund),
            ],
        };
    }
}
