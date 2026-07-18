<?php

namespace App\Services\Alerts;

use App\Enums\BonvoiceCallAlertType;
use App\Models\BonvoiceCallAlert;
use App\Support\AppDateFormatter;

class IncomingCallTelegramMessageBuilder
{
    public function build(BonvoiceCallAlert $alert, string $actionUrl): string
    {
        $alert->loadMissing(['order', 'incident']);

        $title = $alert->alert_type === BonvoiceCallAlertType::CustomerFound
            ? '📞 Incoming Call'
            : '📞 New Caller';

        $customerName = filled($alert->order?->customer_name)
            ? (string) $alert->order->customer_name
            : 'Unknown';

        $reference = $alert->incident?->reference_no
            ?? $alert->order?->order_id
            ?? '—';

        $time = AppDateFormatter::datetime(now()) ?? now()->toDateTimeString();

        return implode("\n", [
            $title,
            '',
            'Customer: '.$customerName,
            'Mobile: '.$this->maskMobile($alert->customer_phone),
            'Reference: '.$reference,
            'Time: '.$time,
            '',
            'Open in Radium Desk',
            $actionUrl,
        ]);
    }

    public function maskMobile(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        if ($digits === '') {
            return 'Unknown';
        }

        if (strlen($digits) <= 4) {
            return str_repeat('*', strlen($digits));
        }

        return str_repeat('*', strlen($digits) - 4).substr($digits, -4);
    }
}
