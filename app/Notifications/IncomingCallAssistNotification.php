<?php

namespace App\Notifications;

use App\Enums\BonvoiceCallAlertType;
use App\Models\BonvoiceCallAlert;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class IncomingCallAssistNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly BonvoiceCallAlert $alert,
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
            'title' => $this->title(),
            'message' => $this->message(),
            'url' => $this->actionUrl(),
        ];
    }

    private function title(): string
    {
        return $this->alert->alert_type === BonvoiceCallAlertType::CustomerFound
            ? '📞 Incoming Call'
            : '📞 New Caller';
    }

    private function message(): string
    {
        if ($this->alert->alert_type === BonvoiceCallAlertType::CustomerFound) {
            $orderLabel = $this->alert->order?->order_id ?? 'Customer';

            return "Customer Found: {$orderLabel}";
        }

        $phone = $this->alert->customer_phone ?? 'Unknown';

        return "Mobile: {$phone}\nNo existing record";
    }

    private function actionUrl(): string
    {
        if ($this->alert->incident_id !== null) {
            return route('incidents.show', $this->alert->incident_id);
        }

        return route('dashboard');
    }
}
