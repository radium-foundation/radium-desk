<?php

namespace App\Notifications;

use App\Models\Order;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class TransactionCompletedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Order $order,
        private readonly string $transactionId,
        private readonly User $completedBy,
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
            'title' => 'Transaction ID Added',
            'message' => "Transaction {$this->transactionId} was added to order {$this->order->order_id} by {$this->completedBy->firstName()}.",
            'url' => route('orders.show', $this->order),
        ];
    }
}
